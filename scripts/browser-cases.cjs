'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const { chromium } = require(process.env.AUTOMATION_TEST_PLAYWRIGHT || 'playwright');

async function main() {
  const [, , origin, output] = process.argv;
  assert.match(origin, /^http:\/\/127\.0\.0\.1:\d+$/);
  assert.ok(output);
  const profiles = {
    'fixture-admin': process.env.AUTOMATION_SMOKE_TOKEN,
    investigator: process.env.AUTOMATION_CASE_TOKEN_INVESTIGATOR,
    operations: process.env.AUTOMATION_CASE_TOKEN_OPERATIONS,
    finance: process.env.AUTOMATION_CASE_TOKEN_FINANCE,
    'decision-owner': process.env.AUTOMATION_CASE_TOKEN_DECISION_OWNER,
  };
  const browser = await chromium.launch({
    headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE || undefined,
  });
  try {
    const page = await browser.newPage({ viewport: { width: 1260, height: 900 }, acceptDownloads: true });
    const errors = [];
    const failures = [];
    page.on('pageerror', (error) => errors.push(error.message));
    page.on('response', (response) => { if (response.status() >= 400) failures.push([response.status(), response.url()]); });
    const api = async (path) => {
      const request = await page.request.get(origin + '/api/' + path, { headers: { Authorization: 'Bearer ' + profiles['fixture-admin'] } });
      assert.equal(request.status(), 200);
      return request.json();
    };
    const login = async (actor, id) => {
      assert.ok(profiles[actor]);
      await page.locator('#token').fill(profiles[actor]);
      await page.getByRole('button', { name: '连接', exact: true }).click();
      await page.locator('#who').filter({ hasText: actor }).waitFor();
      await page.locator('#cases-tab').click();
      if (id) {
        await page.locator('#case-list button').filter({ hasText: id }).click();
        await page.locator('#case-title').filter({ hasText: 'Package arrived damaged' }).waitFor();
      }
    };
    const waitForText = (selector, value) => page.locator(selector).filter({ hasText: value }).waitFor();
    const task = async (role, operation) => {
      const labels = { investigate: '调查操作', 'review-operations': '运营复核操作', 'review-finance': '财务复核操作', decision: '拟办操作' };
      const select = page.getByLabel(labels[role], { exact: true });
      await select.selectOption(operation);
      await select.locator('..').getByRole('button', { name: '提交任务操作' }).click();
    };

    await page.goto(origin);
    await login('fixture-admin');
    await page.locator('#case-template-panel summary').click();
    await page.locator('#case-template-preview').click();
    await waitForText('#message', '匹配受理来源');
    await page.getByRole('button', { name: '发布模板' }).click();
    await waitForText('#message', '模板版本 1');
    await page.locator('#case-intake-panel summary').click();
    for (const [field, value] of Object.entries({ external: 'browser-returns-1', customer: 'demo-customer',
      order: 'ORD-4001', subject: 'Package arrived damaged' })) await page.locator('#case-' + field).fill(value);
    await page.getByRole('button', { name: '受理', exact: true }).click();
    await waitForText('#case-title', 'Package arrived damaged');
    const caseID = (await api('cases'))[0].case_id;
    for (const [index, kind] of ['customer-summary', 'order', 'payment-proof'].entries()) {
      await page.locator('#case-evidence-kind').selectOption(kind);
      await page.locator('#case-evidence-verified').check();
      await page.getByRole('button', { name: '登记证据' }).click();
      await waitForText('#case-overview', '证据 ' + (index + 1));
    }
    await login('investigator', caseID);
    assert.equal(await page.locator('#case-evidence .case-item').count(), 2);
    await task('investigate', 'claim');
    await page.getByLabel('调查操作', { exact: true }).locator('option[value="complete"]').waitFor({ state: 'attached' });
    await task('investigate', 'complete');
    await waitForText('#case-overview', '部门复核');
    await login('operations', caseID);
    await task('review-operations', 'claim');
    await page.getByLabel('运营复核操作', { exact: true }).locator('option[value="complete"]').waitFor({ state: 'attached' });
    await task('review-operations', 'complete');
    await waitForText('#case-assignments', 'operations · complete');
    await login('finance', caseID);
    assert.equal(await page.locator('#case-evidence .case-item').count(), 3);
    await task('review-finance', 'claim');
    await page.getByLabel('财务复核操作', { exact: true }).locator('option[value="complete"]').waitFor({ state: 'attached' });
    await task('review-finance', 'complete');
    await waitForText('#case-overview', '拟办');
    await login('decision-owner', caseID);
    await task('decision', 'claim');
    await page.locator('#case-payee').fill('demo-original');
    await page.locator('#case-reason').fill('The damage was documented and the payment was verified.');
    await page.getByRole('button', { name: '提交待批准决定' }).click();
    await waitForText('#case-overview', '待批准');
    await login('operations', caseID);
    await page.getByRole('button', { name: '以运营身份批准' }).click();
    await waitForText('#message', '等待另一部门批准');
    await login('finance', caseID);
    await page.getByRole('button', { name: '以财务身份批准' }).click();
    await waitForText('#message', '两份批准已生效');
    let decision;
    for (let attempt = 0; attempt < 30; attempt++) {
      [decision] = await api('cases/' + caseID + '/decisions');
      if (decision?.receipt_id) break;
      await new Promise((resolve) => setTimeout(resolve, 100));
    }
    assert.ok(decision.receipt_id);
    assert.equal(decision.state, 'executed');
    assert.equal(decision.outcome.amount_minor, 10000);
    assert.equal(decision.votes.length, 2);
    await page.setViewportSize({ width: 390, height: 844 });
    await login('fixture-admin', caseID);
    await waitForText('#case-overview', '已办结');
    await waitForText('#case-decisions', decision.receipt_id);
    const dimensions = await page.evaluate(() => ({ viewport: innerWidth, width: document.documentElement.scrollWidth }));
    assert.ok(dimensions.width <= dimensions.viewport, JSON.stringify(dimensions));
    const [download] = await Promise.all([page.waitForEvent('download'), page.locator('#case-export').click()]);
    const snapshot = JSON.parse(fs.readFileSync(await download.path(), 'utf8'));
    assert.equal(snapshot.case.case_id, caseID);
    assert.equal(snapshot.decisions[0].receipt_id, decision.receipt_id);
    assert.equal(snapshot.timeline.filter((event) => event.action === 'case-executed').length, 1);
    assert.deepEqual(errors, []);
    assert.deepEqual(failures, []);
    fs.writeFileSync(output, JSON.stringify({ browser: browser.version(), mobile_css_width: dimensions.viewport, browser_errors: errors,
      token_roles: Object.keys(profiles), case_id: caseID, evidence_count: snapshot.evidence.length, decision_id: decision.decision_id,
      receipt_id: decision.receipt_id, amount_minor: decision.outcome.amount_minor, signatures: decision.votes.length,
      final_state: snapshot.case.state, timeline_events: snapshot.timeline.length, export_verified: true }, null, 2) + '\n');
  } finally {
    await browser.close();
  }
}

main().catch((error) => { console.error(error); process.exitCode = 1; });
