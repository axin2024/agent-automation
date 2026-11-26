'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const { chromium } = require(process.env.AUTOMATION_TEST_PLAYWRIGHT || 'playwright');

async function main() {
  const [, , origin, output] = process.argv;
  const token = process.env.AUTOMATION_SMOKE_TOKEN;
  assert.match(origin, /^http:\/\/127\.0\.0\.1:\d+$/);
  assert.ok(token && output);
  const browser = await chromium.launch({
    headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE || undefined,
  });
  try {
    const page = await browser.newPage();
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    const api = async (path, data) => {
      const options = { headers: { Authorization: 'Bearer ' + token } };
      const url = origin + '/api/' + path;
      const response = data === undefined ? await page.request.get(url, options) : await page.request.post(url, { ...options, data });
      assert.equal(response.status(), 200);
      return response.json();
    };
    const original = await api('flows/customer-care/versions/1');
    const template = await api('flows/customer-care/templates/1');
    const receiptBefore = await api('inbox');
    const login = async () => {
      await page.locator('#token').fill(token);
      await page.getByRole('button', { name: '连接', exact: true }).click();
      await page.locator('#who').filter({ hasText: 'fixture-admin' }).waitFor();
    };
    await page.goto(origin);
    await login();
    await page.locator('#template-import summary').click();
    await page.locator('#template-file').setInputFiles({
      name: 'workflow-template.json', mimeType: 'application/json', buffer: Buffer.from(JSON.stringify(template)),
    });
    for (const [alias, local] of Object.entries(original.bindings)) {
      await page.locator('#template-connections select[name="' + alias + '"]').selectOption(local);
    }
    await page.locator('#template-rebind button').click();
    assert.deepEqual(JSON.parse(await page.locator('#event').inputValue()), template.fixtures[0]);
    assert.deepEqual(JSON.parse(await page.locator('#definition').inputValue()), template.definition);
    await page.locator('#flow').fill('portable-copy');
    await page.locator('#save').click();
    await page.getByRole('status').filter({ hasText: '已保存修订' }).waitFor();
    const copy = await api('flows/portable-copy/versions/1');
    const flows = await api('flows');
    assert.equal(copy.digest, original.digest);
    assert.equal(flows.find((item) => item.flow === 'portable-copy').active, null);
    assert.equal(flows.find((item) => item.flow === 'customer-care').active, 1);
    const review = await testReview(page, api, login, template.fixtures[0]);
    assert.deepEqual(await api('inbox'), receiptBefore);
    assert.deepEqual(errors, []);
    fs.writeFileSync(output, JSON.stringify({
      browser: browser.version(), graph_digest: copy.digest, scoped_bindings: Object.keys(copy.bindings),
      fixture_loaded: true, imported_revision: copy.revision, imported_active: null, source_active: 1,
      extra_deliveries: 0, review_batch: review,
    }, null, 2) + '\n');
  } finally {
    await browser.close();
  }
}

async function testReview(page, api, login, event) {
  const trigger = (suffix) => api('flows/customer-care/events', {
    source: 'browser-batch', event: { ...event, message: '<review-' + suffix + '@example.test>' },
  });
  const first = await trigger('first');
  const stale = await trigger('stale');
  for (const run of [first, stale]) {
    let current;
    for (let attempt = 0; attempt < 50; attempt++) {
      current = await api('runs/' + run.id);
      if (current.state === 'waiting-review') break;
      await new Promise((resolve) => setTimeout(resolve, 100));
    }
    assert.equal(current.state, 'waiting-review');
  }
  await page.locator('#runs-tab').click();
  await page.locator('#approval-list button').filter({ hasText: first.id }).click();
  await page.locator('#feedback-node').selectOption('classify');
  await page.locator('#feedback-label').selectOption('extraction-correct');
  await page.locator('#run-feedback button').click();
  await page.locator('#feedback-records li').filter({ hasText: 'classify · 字段提取正确 · fixture-admin' }).waitFor();
  await page.locator('#review-assignee').fill('fixture-admin');
  await page.locator('#review-assignment button').click();
  await page.locator('#reviewer-status').filter({ hasText: 'fixture-admin' }).waitFor();
  await page.locator('#approval-reviewer').fill('fixture-admin');
  await page.locator('#approval-filters button').click();
  await page.locator('#approval-list button').filter({ hasText: stale.id }).waitFor({ state: 'detached' });
  assert.equal(await page.locator('#approval-list button').count(), 1);
  await page.locator('#filter-flow').fill('customer-care');
  await page.locator('#filter').selectOption('waiting-review');
  await page.locator('#run-filters button').click();
  for (const run of [first, stale]) {
    await page.locator('#run-list button').filter({ hasText: run.id }).click();
    await page.locator('#selected-run').filter({ hasText: run.id }).waitFor();
    await page.locator('#queue-reject').click();
  }
  let dropped = 0;
  await page.route('**/api/review-batches', async (route) => {
    if (route.request().method() !== 'POST') return route.continue();
    const response = await route.fetch();
    assert.equal(response.status(), 200);
    dropped++;
    await route.abort('connectionreset');
  });
  await page.locator('#save-batch').click();
  await page.locator('#batch-progress').filter({ hasText: '0 / 2' }).waitFor();
  const id = await page.locator('#batch-id').inputValue();
  const staleApproval = (await api('runs/' + stale.id)).approvals.find((item) => item.state === 'pending');
  await api('runs/' + stale.id + '/approvals/review', {
    action: 'revise', revision: staleApproval.revision, changes: { recipient: 'replacement@example.test' },
  });
  const runRoute = '**/api/review-batches/' + id + '/run';
  await page.route(runRoute, async (route) => {
    const response = await route.fetch({ postData: { limit: 1 } });
    assert.equal(response.status(), 200);
    dropped++;
    await route.abort('connectionreset');
  }, { times: 1 });
  await page.locator('#run-batch').click();
  await page.locator('#batch-progress').filter({ hasText: '1 / 2' }).waitFor();
  await page.reload();
  await login();
  await page.locator('#runs-tab').click();
  await page.locator('#recent-batches option[value="' + id + '"]').waitFor({ state: 'attached' });
  await page.locator('#recent-batches').selectOption(id);
  await page.locator('#batch-progress').filter({ hasText: '1 / 2' }).waitFor();
  await page.locator('#run-batch').click();
  await page.locator('#batch-progress').filter({ hasText: '2 / 2' }).waitFor();
  const receipt = await api('review-batches/' + id);
  assert.deepEqual(receipt.items.map((item) => item.state), ['applied', 'rejected']);
  assert.equal(receipt.items[1].error, 'state-conflict');
  assert.equal(dropped, 2);
  return { assignee_filter: 'fixture-admin', evaluation_saved: true, dropped_responses: dropped, restored_cursor: 1, finished_items: receipt.completed,
    outcomes: receipt.items.map((item) => item.state), stale_revision: receipt.items[1].error };
}

main().catch((error) => { process.stderr.write(String(error.stack || error) + '\n'); process.exitCode = 1; });
