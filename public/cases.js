'use strict';

const stages = { investigating: '调查中', 'needs-evidence': '待补件', review: '部门复核', decision: '拟办',
  'awaiting-votes': '待批准', 'ready-to-dispatch': '待执行', reconcile: '待查单', closed: '已办结' };
const taskNames = { investigate: '调查', 'review-operations': '运营复核', 'review-finance': '财务复核', decision: '拟办' };
const assistantNames = { classify: '案件分类', investigate: '调查摘要', recommend: '拟办意见' };
const taskStages = { investigate: ['investigating', 'needs-evidence'], 'review-operations': ['review'], 'review-finance': ['review'], decision: ['decision'] };
const assistantGroups = { classify: ['investigate'], investigate: ['investigate', 'review-operations'], recommend: ['review-finance', 'decision'] };
const assignmentActions = { assign: '分派', transfer: '转派', delegate: '授权代办', revoke: '撤销代办', complete: '完成', claim: '认领' };
const evidenceExamples = {
  'customer-summary': ['workspace', 'issue_code', { issue_code: 'delivery' }],
  order: ['workspace', 'status', { status: 'delivered' }],
  'payment-proof': ['finance', 'status,ceiling_minor', { status: 'captured', ceiling_minor: 20000, payees: ['demo-original', 'demo-alternate'] }],
};
const exampleCaseTemplate = {
  sources: ['after-sales', 'email'],
  required_items: ['customer-summary', 'order', 'payment-proof'],
  people: { investigate: ['investigator'], 'review-operations': ['operations'], 'review-finance': ['finance'], decision: ['decision-owner'] },
  sla_minutes: { investigate: 60, 'review-operations': 120, 'review-finance': 180, decision: 240 },
  calendar: { zone: 'Asia/Shanghai', opens: 9, closes: 17, weekdays: [1, 2, 3, 4, 5], holidays: [] },
  refund_limit_minor: 25000,
  escalation_recipient: 'supervisor',
};
let activeCase;
let caseQuery = 0;
let caseIntakeEnabled;

function resetCases() {
  activeCase = undefined;
  caseIntakeEnabled = undefined;
  caseQuery++;
  el('case-intake-policy').hidden = true;
  el('case-detail').hidden = el('case-export').hidden = el('case-metrics').hidden = true;
  el('case-list').replaceChildren();
  el('case-types').replaceChildren();
  display('case-title', '案件详情');
  display('case-caption', '从待办列表打开一个案件。');
  for (const id of ['case-intake', 'case-filters', 'case-template', 'case-propose', 'case-link', 'case-reopen', 'case-evidence-form']) el(id).reset();
  el('template-case-json').value = JSON.stringify(exampleCaseTemplate, null, 2);
  setEvidenceExample();
}

function caseText(tag, value) {
  const node = document.createElement(tag);
  node.textContent = value;
  return node;
}

function onCaseControl(control, event, callback) {
  control.addEventListener(event, async (event) => {
    event.preventDefault();
    const button = event.submitter || control;
    button.disabled = true;
    msg('');
    try { await callback(); } catch (error) { msg(error.message, true); } finally { button.disabled = false; }
  });
}

function caseButton(label, callback) {
  const button = caseText('button', label);
  button.className = 'secondary';
  button.type = 'button';
  onCaseControl(button, 'click', callback);
  return button;
}

function caseCard(title, detail = '') {
  const card = document.createElement('article');
  card.className = 'case-item';
  card.append(caseText('strong', title));
  if (detail) card.append(caseText('p', detail));
  return card;
}

const caseTime = (time) => time ? time.slice(0, 16) + ' UTC' : '';
const fields = (text) => text.split(',').map((part) => part.trim()).filter(Boolean);
const caseGroup = (task) => ['investigate', 'review-operations'].includes(task) ? 'operator' : 'approver';
const caseMember = (task) => scope.role === caseGroup(task) && activeCase.template.people[task].includes(scope.actor);
const adminCase = () => scope.role === 'admin';

async function listCases() {
  if (!can('operator', 'approver', 'author')) return;
  const query = new URLSearchParams({ limit: '50' });
  if (read('case-state')) query.set('state', read('case-state'));
  if (read('case-search')) query.set('search', read('case-search'));
  if (read('case-due')) query.set('due_before', utcTime(read('case-due')));
  if (el('case-mine').checked) query.set('mine', 'yes');
  const [records, templates] = await Promise.all([request('cases?' + query), request('case-types')]);
  el('case-types').replaceChildren(...templates.map((record) => new Option(record.case_type, record.case_type)));
  el('case-list').replaceChildren(...records.map((record) => {
    const item = caseButton(record.subject, () => openCase(record.case_id));
    item.className = 'run-link';
    item.append(caseText('span', [stages[record.state] || record.state, '轮次 ' + record.case_round,
      record.escalated ? '已督办' : '', caseTime(record.deadline)].filter(Boolean).join(' · ')));
    item.append(caseText('span', record.case_id));
    return item;
  }));
  if (!records.length) el('case-list').append(caseText('p', '当前筛选条件下没有案件。'));
}

async function openCase(id) {
  const query = ++caseQuery;
  const path = 'cases/' + id;
  const [record, evidence, proposals, decisions, events] = await Promise.all(
    [path, path + '/evidence', path + '/assistant', path + '/decisions', path + '/timeline'].map((route) => request(route)));
  if (query !== caseQuery) return;
  activeCase = record;
  el('case-detail').hidden = el('case-export').hidden = false;
  display('case-title', record.subject);
  display('case-caption', ['外部单号 ' + record.external_ref, record.source, record.case_id].join(' · '));
  el('case-overview').replaceChildren(...[
    stages[record.state] || record.state, '轮次 ' + record.case_round, '模板 ' + record.case_type + ' v' + record.case_type_rev,
    '证据 ' + record.evidence_rev, '到期 ' + caseTime(record.deadline), ...(record.order_no ? ['订单 ' + record.order_no] : []),
  ].map((value) => caseText('span', value)));
  const editable = !['closed', 'reconcile'].includes(record.state);
  const assistantOpen = ['investigating', 'needs-evidence', 'review', 'decision'].includes(record.state);
  el('case-evidence-form').hidden = !editable || !(adminCase() || caseMember('investigate') || caseMember('review-finance'));
  el('case-propose').hidden = !['decision', 'awaiting-votes', 'ready-to-dispatch'].includes(record.state)
    || !record.assignments.some((task) => task.case_round === record.case_round && task.task_role === 'decision' &&
      [task.assignee, task.delegate].includes(scope.actor));
  el('case-link').hidden = !can('operator') || !record.template.workflow;
  el('case-reopen').hidden = !adminCase() || record.state !== 'closed';
  for (const button of el('case-assistant-buttons').querySelectorAll('button')) {
    button.hidden = !assistantOpen || !(adminCase() || assistantGroups[button.dataset.assistant].some(caseMember));
  }
  el('case-assignments').replaceChildren(...record.assignments.map(assignmentCard));
  el('case-evidence').replaceChildren(...evidence.map(evidenceCard));
  el('case-suggestions').replaceChildren(...proposals.map((proposal) => assistantCard(proposal, assistantOpen)));
  el('case-decisions').replaceChildren(...decisions.map(decisionCard));
  el('case-links').replaceChildren(...record.runs.map((run) => caseCard(run.flow + ' v' + run.graph_revision + ' · ' + run.state,
    run.run + ' · ' + run.actor + ' · ' + caseTime(run.linked_at))));
  el('case-events').replaceChildren(...events.map((event) => {
    const card = caseCard(event.action, event.actor + ' · ' + caseTime(event.happened_at));
    card.append(caseText('small', JSON.stringify(event.detail)));
    return card;
  }));
}

function assignmentCard(task) {
  const card = caseCard(taskNames[task.task_role] + ' · 第 ' + task.case_round + ' 轮',
    [task.assignee || '未分派', task.state, caseTime(task.due_at), '租约 ' + task.lease_rev].join(' · '));
  if (task.delegate) card.append(caseText('small', '代办 ' + task.delegate + ' · ' + caseTime(task.delegated_until)));
  if (task.handoff) card.append(caseText('small', task.handoff));
  if (task.case_round !== activeCase.case_round || task.state === 'complete' || !taskStages[task.task_role].includes(activeCase.state)) return card;
  const available = [];
  if (caseMember(task.task_role) && !task.assignee) available.push('claim');
  if (adminCase() || task.assignee === scope.actor) available.push('assign', 'transfer', 'delegate', 'revoke');
  if (task.task_role !== 'decision' && scope.role === caseGroup(task.task_role) && [task.assignee, task.delegate].includes(scope.actor)) {
    available.push('complete');
  }
  if (available.length) card.append(assignmentForm(task, available));
  return card;
}

function caseInput(label, value = '') {
  const input = document.createElement('input');
  input.setAttribute('aria-label', label);
  input.placeholder = label;
  input.value = value;
  return input;
}

function assignmentForm(task, operations) {
  const form = document.createElement('form');
  const select = document.createElement('select');
  select.replaceChildren(...operations.map((operation) => new Option(assignmentActions[operation], operation)));
  select.setAttribute('aria-label', taskNames[task.task_role] + '操作');
  const actor = caseInput('接手账号');
  const handoff = caseInput('办理说明');
  const hours = caseInput('代办时长（小时）', '2');
  Object.assign(hours, { type: 'number', min: 1, max: 168 });
  form.append(select, actor, handoff, hours, caseText('button', '提交任务操作'));
  function updateFields() {
    actor.hidden = !['assign', 'transfer', 'delegate'].includes(select.value);
    handoff.hidden = ['claim', 'revoke'].includes(select.value);
    hours.hidden = select.value !== 'delegate';
  }
  select.addEventListener('change', updateFields);
  updateFields();
  onCaseControl(form, 'submit', async () => {
    const result = await mutateCase('assignments/' + task.task_role, { assignment_id: task.assignment_id,
      lease_rev: task.lease_rev, operation: select.value, evidence_rev: activeCase.evidence_rev,
      actor: actor.value.trim(), hours: Number(hours.value), handoff: handoff.value.trim() });
    if (result.missing?.length) msg('请先补齐已核实证据 · ' + result.missing.join('、'));
  });
  return form;
}

function evidenceCard(record) {
  const card = caseCard(record.kind + ' · 证据 ' + record.evidence_rev,
    [record.source, record.verified ? '已核实' : '待核实', record.actor].join(' · '));
  card.id = 'evidence-' + record.evidence_id;
  card.tabIndex = -1;
  card.append(caseText('small', '助手可读字段 ' + (record.allowed_fields.join('、') || '无')));
  const detail = document.createElement('details');
  detail.append(caseText('summary', '查看材料'), caseText('pre', JSON.stringify(record.payload, null, 2)));
  card.append(detail);
  return card;
}

function assistantCard(proposal, canEdit) {
  const card = caseCard(assistantNames[proposal.assistant_role] + ' · ' + proposal.state,
    [proposal.suggestion?.label, proposal.suggestion?.reason, proposal.actor, proposal.usage?.model].filter(Boolean).join(' · '));
  card.append(caseText('small', proposal.proposal_id));
  if (proposal.based_on) card.append(caseText('small', '原意见 ' + proposal.based_on));
  if (proposal.suggestion?.note) card.append(caseText('p', proposal.suggestion.note));
  for (const citation of proposal.suggestion?.citations ?? []) {
    const link = caseText('a', citation.kind + ' · ' + citation.source);
    link.href = '#evidence-' + citation.evidence_id;
    link.addEventListener('click', () => { el('case-evidence-panel').open = true; });
    card.append(link);
  }
  if (canEdit && proposal.evidence_rev === activeCase.evidence_rev && ['ready', 'needs-human'].includes(proposal.state)
      && (adminCase() || assistantGroups[proposal.assistant_role].some(caseMember))) {
    const details = document.createElement('details');
    const form = document.createElement('form');
    const label = caseInput('修订结论', proposal.suggestion?.label ?? 'insufficient');
    const references = caseInput('证据编号，多个编号使用逗号隔开',
      (proposal.suggestion?.citations ?? []).map((citation) => citation.evidence_id).join(','));
    const note = caseInput('修订原因');
    note.required = true;
    form.append(label, references, note, caseText('button', '保留修订'));
    onCaseControl(form, 'submit', () => mutateCase('assistant/revise', { based_on: proposal.proposal_id, label: label.value.trim(),
      citations: fields(references.value), note: note.value.trim() }));
    details.append(caseText('summary', '修订意见'), form);
    card.append(details);
  }
  return card;
}

function decisionCard(decision) {
  const args = decision.operational_args;
  const card = caseCard([args.kind === 'refund' ? '演示退款' : '结案', decision.state, '第 ' + decision.case_round + ' 轮'].join(' · '),
    args.kind === 'refund' ? args.amount_minor + ' 最小货币单位 · ' + args.payee : '');
  card.append(caseText('small', '决定 ' + decision.decision_id + ' · 证据 ' + decision.evidence_rev));
  card.append(caseText('small', '参数 ' + decision.args_hash));
  if (decision.suggestion?.reason) card.append(caseText('p', decision.suggestion.reason));
  if (decision.failure) card.append(caseText('small', '原因 ' + decision.failure));
  if (decision.receipt_id) card.append(caseText('small', '回执 ' + decision.receipt_id));
  if (decision.retryable && (adminCase() || caseMember('review-finance'))) {
    card.append(caseButton('核对受理回执', () => mutateCase('decisions/' + decision.decision_id + '/reconcile', {})));
  }
  for (const vote of decision.votes) card.append(caseText('small', [vote.approval_role, vote.actor, caseTime(vote.signed_at)].join(' · ')));
  for (const role of ['operations', 'finance']) {
    if (decision.state !== 'awaiting-votes' || !caseMember('review-' + role) ||
        decision.votes.some((vote) => vote.actor === scope.actor || vote.approval_role === role)) continue;
    card.append(caseButton(role === 'finance' ? '以财务身份批准' : '以运营身份批准', async () => {
      const receipt = await mutateCase('decisions', { proposal_id: decision.proposal_id, evidence_rev: decision.evidence_rev,
        args_hash: decision.args_hash, approval_role: role });
      msg(receipt.dispatch_state === 'queued' ? '两份批准已生效，正在等待执行回执。' : '已签核，等待另一部门批准。');
    }));
  }
  return card;
}

function setEvidenceExample() {
  const [visibility, allowed, data] = evidenceExamples[read('case-evidence-kind')];
  el('case-evidence-visibility').value = visibility;
  el('case-evidence-fields').value = allowed;
  el('case-evidence-data').value = JSON.stringify({ ...data, fragment: '', source_ref: '' }, null, 2);
}

async function mutateCase(section, data) {
  const id = activeCase.case_id;
  const result = await request('cases/' + id + '/' + section, { revision: activeCase.revision, ...data }, crypto.randomUUID());
  await Promise.all([openCase(id), listCases()]);
  return result;
}

action('cases-tab', 'click', async () => {
  switchTab('cases');
  el('case-queue-panel').hidden = !can('operator', 'approver', 'author');
  el('case-intake-panel').hidden = !can('operator', 'trigger');
  el('case-template-panel').hidden = !adminCase();
  if (adminCase() && Number(read('template-case-revision')) === 0) {
    const template = JSON.parse(read('template-case-json'));
    template.escalation_recipient = scope.actor;
    el('template-case-json').value = JSON.stringify(template, null, 2);
  }
  await listCases();
});
action('case-filters', 'submit', listCases);
action('case-refresh', 'click', async () => { await Promise.all([listCases(), activeCase ? openCase(activeCase.case_id) : null]); });
action('case-intake', 'submit', async () => {
  const record = await request('cases', { case_type: read('case-type'), source: read('case-source'),
    external_ref: read('case-external'), customer_ref: read('case-customer'), subject: read('case-subject'),
    ...(read('case-order') ? { order_no: read('case-order') } : {}) }, crypto.randomUUID());
  msg('已受理 · ' + record.case_id);
  if (can('operator')) await Promise.all([listCases(), openCase(record.case_id)]);
});
action('case-template-preview', 'click', async () => {
  const result = await request('case-types/preview', { definition: JSON.parse(read('template-case-json')), source: read('case-source') });
  msg((result.matches ? '匹配受理来源' : '来源不匹配') + ' · 所需证据 ' + result.required_items.join('、'));
});
action('case-template-open', 'click', async () => {
  const result = await request('case-types/' + encodeURIComponent(read('template-case-type')));
  el('template-case-revision').value = result.case_type_rev;
  el('template-case-json').value = JSON.stringify(result.definition, null, 2);
});
action('case-template', 'submit', async () => {
  const result = await request('case-types', { case_type: read('template-case-type'),
    expected: Number(read('template-case-revision')), definition: JSON.parse(read('template-case-json')) });
  el('template-case-revision').value = result.case_type_rev;
  await listCases();
  msg('模板版本 ' + result.case_type_rev + ' 已发布。');
});
async function caseMetrics() {
  const report = await request('cases/metrics');
  caseIntakeEnabled = report.intake_enabled;
  el('case-intake-policy').hidden = false;
  display('case-intake-policy', caseIntakeEnabled ? '暂停受理新案件' : '开放案件受理');
  write('case-metrics', report);
  el('case-metrics').hidden = false;
}
action('case-metrics-load', 'click', caseMetrics);
action('case-preflight', 'click', async () => {
  write('case-metrics', await request('cases/preflight'));
  el('case-metrics').hidden = false;
});
action('case-intake-policy', 'click', async () => {
  await request('cases/intake-policy', { enabled: !caseIntakeEnabled });
  await caseMetrics();
});
action('case-evidence-kind', 'change', setEvidenceExample);
action('case-evidence-form', 'submit', async () => {
  await mutateCase('evidence', { kind: read('case-evidence-kind'), visibility: read('case-evidence-visibility'),
    source: read('case-evidence-source'), verified: el('case-evidence-verified').checked,
    allowed_fields: fields(read('case-evidence-fields')), payload: JSON.parse(read('case-evidence-data')) });
});
for (const button of el('case-assistant-buttons').querySelectorAll('button')) {
  button.id = 'assist-case-' + button.dataset.assistant;
  action(button.id, 'click', async () => { await mutateCase('assistant/' + button.dataset.assistant, {}); });
}
action('case-propose', 'submit', async () => {
  const kind = read('case-action');
  await mutateCase('propose', { kind, reason: read('case-reason'),
    ...(kind === 'refund' ? { amount_minor: Number(read('case-amount')), payee: read('case-payee') } : {}),
    ...(read('case-based-on') ? { based_on: read('case-based-on') } : {}) });
});
action('case-export', 'click', async () => {
  const id = activeCase.case_id;
  const snapshot = await request('cases/' + id + '/export');
  const url = URL.createObjectURL(new Blob([JSON.stringify(snapshot, null, 2)], { type: 'application/json' }));
  const download = document.createElement('a');
  download.href = url;
  download.download = 'case-' + id + '.json';
  download.click();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
});
action('case-link', 'submit', async () => { await mutateCase('link-run', { run: read('case-run-id') }); });
action('case-reopen', 'submit', async () => { await mutateCase('reopen', { reason: read('case-reopen-reason') }); });
