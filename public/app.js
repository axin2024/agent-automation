'use strict';

const el = (id) => document.getElementById(id);
const read = (id) => el(id).value.trim();
const display = (id, content) => { el(id).textContent = content; };
const write = (id, object) => display(id, JSON.stringify(object, null, 2));
const nodeID = /^[a-z][a-z0-9_-]{0,63}$/;
let token = '';
let session = 0;
let scope;
let graph = { nodes: {} };
let contracts = {};
let selectedNode = '';
let selectedRun;
let selectedApproval;
let selectedDelivery;
let nextPage = '';

function msg(text, failed = false) {
  display('message', text);
  el('message').hidden = !text;
  el('message').classList.toggle('error', failed);
}

function can(...roles) {
  return scope?.role === 'admin' || roles.includes(scope?.role);
}

async function request(path, data) {
  const expected = session;
  const options = { headers: { Authorization: 'Bearer ' + token }, cache: 'no-store' };
  if (data !== undefined) {
    options.method = 'POST';
    options.headers['Content-Type'] = 'application/json';
    options.body = JSON.stringify(data);
  }
  const response = await fetch('/api/' + path, options);
  const result = await response.json();
  if (expected !== session) throw new Error('环境凭据已更换');
  if (!response.ok) throw new Error(result.message || '工作区请求失败');
  return result;
}

function action(id, method, callback) {
  el(id).addEventListener(method, async (event) => {
    event.preventDefault();
    const control = method === 'submit' ? event.submitter : event.currentTarget;
    if (control && method !== 'change') control.disabled = true;
    msg('');
    try {
      await callback();
    } catch (error) {
      msg(error.message, true);
    } finally {
      if (control && method !== 'change') control.disabled = false;
    }
  });
}

function switchTab(runs) {
  el('design').hidden = !scope || runs;
  el('operations').hidden = !scope || !runs;
  el('design-tab').setAttribute('aria-pressed', String(!runs));
  el('runs-tab').setAttribute('aria-pressed', String(runs));
}

function assignGraph(value) {
  if (!value?.nodes || Array.isArray(value.nodes) || typeof value.nodes !== 'object' ||
      Object.keys(value.nodes).length > 64 || Object.entries(value.nodes).some(([id, node]) => !nodeID.test(id) || typeof node?.kind !== 'string')) {
    throw new Error('流程需要一个有效的节点对象');
  }
  graph = { nodes: value.nodes };
  selectedNode = '';
  el('node-form').hidden = true;
  renderGraph();
}

function renderGraph() {
  el('definition').value = JSON.stringify(graph, null, 2);
  el('graph').replaceChildren();
  const depths = new Map();
  if (Object.hasOwn(graph.nodes, 'start')) {
    depths.set('start', 0);
    const queue = ['start'];
    for (const id of queue) {
      for (const dest of Object.values(graph.nodes[id].edges || {})) {
        if (Object.hasOwn(graph.nodes, dest) && !depths.has(dest)) {
          depths.set(dest, depths.get(id) + 1);
          queue.push(dest);
        }
      }
    }
  }
  const lanes = new Map();
  for (const id of Object.keys(graph.nodes)) {
    const depth = depths.get(id) ?? 100;
    if (!lanes.has(depth)) lanes.set(depth, []);
    lanes.get(depth).push(id);
  }
  for (const entries of [...lanes.entries()].sort(([a], [b]) => a - b)) {
    const lane = document.createElement('div');
    lane.className = 'lane';
    for (const id of entries[1]) {
      const node = graph.nodes[id];
      const button = document.createElement('button');
      button.className = 'node';
      button.type = 'button';
      button.setAttribute('aria-pressed', String(selectedNode === id));
      const heading = document.createElement('strong');
      heading.textContent = id;
      const caption = document.createElement('small');
      caption.className = 'node-kind';
      caption.textContent = node.kind;
      button.append(caption, heading);
      for (const [branch, dest] of Object.entries(node.edges || {})) {
        const target = document.createElement('small');
        target.textContent = branch + ' → ' + (dest || '待连接');
        button.append(target);
      }
      button.addEventListener('click', () => editNode(id));
      lane.append(button);
    }
    el('graph').append(lane);
  }
  if (lanes.size === 0) {
    const empty = document.createElement('p');
    empty.className = 'muted';
    empty.textContent = '载入样例或打开已保存流程。';
    el('graph').append(empty);
  }
}

function editNode(id = '') {
  selectedNode = id;
  const node = Object.hasOwn(graph.nodes, id) ? graph.nodes[id] : { kind: Object.hasOwn(graph.nodes, 'start') ? 'stop' : 'start' };
  el('node-id').value = id || (node.kind === 'start' ? 'start' : '');
  el('node-id').readOnly = !!id;
  el('node-kind').value = node.kind;
  el('node-kind').disabled = id === 'start';
  el('node-options').value = JSON.stringify(node.options || {}, null, 2);
  el('node-form').hidden = false;
  el('node-form').querySelector('button[type="button"]').disabled = !can('author') || !id || id === 'start';
  el('node-form').querySelector('button:not([type])').disabled = !can('author');
  renderPorts(node);
  renderGraph();
}

function renderPorts(node) {
  const spec = contracts[node.kind];
  el('node-ports').replaceChildren();
  if (!spec) return;
  const controls = [
    ...Object.entries(spec.in).map(([port, type]) => [port, 'in', type, node.inputs?.[port] || '']),
    ...spec.branches.map((port) => [port, 'edge', '分支', node.edges?.[port] || '']),
  ];
  if (spec.binding) controls.push(['binding', 'binding', spec.binding, node.binding || '']);
  for (const [port, group, type, value] of controls) {
    const label = document.createElement('label');
    label.textContent = port + ' · ' + type;
    const input = document.createElement('input');
    input.dataset.group = group;
    input.dataset.port = port;
    input.value = value;
    input.required = true;
    label.append(input);
    el('node-ports').append(label);
  }
}

function jsonFrom(id) { return JSON.parse(read(id)); }

async function sample() {
  const response = await fetch('/sample.json', { cache: 'no-store' });
  if (!response.ok) throw new Error('无法加载样例');
  const result = await response.json();
  assignGraph(result.definition);
  el('bindings').value = JSON.stringify(result.bindings, null, 2);
  el('event').value = JSON.stringify(result.event, null, 2);
}

async function save(activate) {
  const result = await request('flows/' + encodeURIComponent(read('flow')), {
    definition: graph, bindings: jsonFrom('bindings'), expected: Number(read('active')), activate,
  });
  el('active').value = result.active ?? 0;
  el('revision').value = result.revision;
  msg('已保存修订 ' + result.revision + ' · ' + result.digest.slice(0, 16));
}

async function runs(reset) {
  if (reset) {
    el('run-list').replaceChildren();
    nextPage = '';
  }
  const params = new URLSearchParams({ state: read('filter'), before: nextPage });
  const result = await request('runs?' + params.toString());
  nextPage = result.next || '';
  el('older').hidden = !nextPage;
  for (const run of result.runs) {
    const entry = document.createElement('button');
    entry.className = 'run-link';
    entry.type = 'button';
    entry.textContent = run.flow + ' · ' + run.state;
    const ref = document.createElement('span');
    ref.textContent = run.id + ' · v' + run.graph_revision;
    entry.append(ref);
    entry.addEventListener('click', () => openRun(run.id).catch((error) => msg(error.message, true)));
    el('run-list').append(entry);
  }
}

async function openRun(id) {
  const run = await request('runs/' + id);
  selectedRun = run;
  selectedApproval = run.approvals.find((item) => item.node === run.cursor && ['pending', 'invalid'].includes(item.state));
  selectedDelivery = run.deliveries.find((item) => item.state !== 'rejected') || run.deliveries[0];
  display('selected-run', run.id + ' · ' + run.state + ' · ' + (run.result || run.cursor));
  el('selected-panel').hidden = false;
  el('correction').hidden = run.state !== 'waiting-input' || !can('operator');
  el('review').hidden = run.state !== 'waiting-review' || !selectedApproval;
  el('dispatch').hidden = !selectedDelivery;
  el('reconcile').hidden = !can('operator') || !['dispatching', 'unknown'].includes(selectedDelivery?.state);
  el('regenerate').hidden = el('revise').hidden = !can('approver', 'operator');
  el('approve').hidden = el('reject').hidden = !can('approver') || selectedApproval?.state !== 'pending';
  if (selectedApproval) {
    el('recipient').value = selectedApproval.draft.recipient;
    el('reply').value = selectedApproval.draft.body;
  }
  if (selectedDelivery) write('delivery', selectedDelivery);
  el('timeline').replaceChildren();
  for (const item of run.history) {
    const line = document.createElement('p');
    line.textContent = item.node + ' · ' + item.outcome;
    el('timeline').append(line);
  }
  write('run-json', run);
}

async function review(action, extra) {
  const id = selectedRun.id;
  await request('runs/' + id + '/approvals/' + selectedApproval.node,
    { action, revision: selectedApproval.revision, digest: selectedApproval.digest, ...extra });
  await openRun(id);
  await runs(true);
}

action('login', 'submit', async () => {
  session++;
  token = read('token');
  el('token').value = '';
  scope = selectedRun = selectedApproval = selectedDelivery = undefined;
  switchTab(false);
  display('environment', '核对凭据');
  display('who', '连接工作区后设计流程、试运行和处理审批。');
  el('selected-panel').hidden = el('inbox-items').hidden = el('metrics').hidden = true;
  for (const id of ['run-json', 'delivery', 'inbox-items', 'metrics']) display(id, '');
  el('run-list').replaceChildren();
  el('timeline').replaceChildren();
  display('selected-run', '选择一条运行记录。');
  assignGraph({ nodes: {} });
  el('active').value = 0;
  el('revision').value = 1;
  el('bindings').value = '{}';
  el('event').value = '';
  [scope, contracts] = await Promise.all([request('workspace'), request('contracts')]);
  display('environment', scope.tenant + ' / ' + scope.environment);
  display('who', scope.actor + ' · ' + scope.role);
  el('node-kind').replaceChildren(...Object.keys(contracts).map((type) => new Option(type, type)));
  for (const id of ['validate', 'add-node', 'save', 'publish', 'activate', 'template']) el(id).hidden = !can('author');
  el('demo-connect').hidden = scope.role !== 'admin' || scope.environment !== 'test';
  el('trigger').hidden = scope.environment !== 'test' || !can('author', 'operator', 'trigger');
  el('inbox').hidden = !can('operator', 'approver');
  switchTab(!can('author'));
  if (!can('author') && can('operator', 'approver')) await runs(true);
});
action('design-tab', 'click', () => switchTab(false));
action('runs-tab', 'click', async () => { switchTab(true); if (scope && can('operator', 'author', 'approver')) await runs(true); });
action('sample', 'click', sample);
action('open-flow', 'click', async () => {
  const name = read('flow');
  const [version, records] = await Promise.all([
    request('flows/' + encodeURIComponent(name) + '/versions/' + Number(read('revision'))),
    request('flows'),
  ]);
  el('active').value = records.find((item) => item.flow === name)?.active ?? 0;
  assignGraph({ nodes: version.graph.nodes });
  el('bindings').value = JSON.stringify(version.bindings, null, 2);
});
action('add-node', 'click', () => editNode());
action('node-kind', 'change', () => renderPorts({ kind: read('node-kind') }));
action('node-form', 'submit', () => {
  const id = read('node-id');
  if (!nodeID.test(id) || !selectedNode && Object.hasOwn(graph.nodes, id)) throw new Error('节点名称无效或已存在');
  const node = { kind: read('node-kind'), inputs: {}, edges: {}, options: jsonFrom('node-options') };
  for (const input of el('node-ports').querySelectorAll('input')) {
    if (input.dataset.group === 'binding') node.binding = input.value.trim();
    if (input.dataset.group === 'in') node.inputs[input.dataset.port] = input.value.trim();
    if (input.dataset.group === 'edge') node.edges[input.dataset.port] = input.value.trim();
  }
  graph.nodes[id] = node;
  editNode(id);
});
action('remove-node', 'click', () => {
  if (!selectedNode || selectedNode === 'start') return;
  delete graph.nodes[selectedNode];
  selectedNode = '';
  el('node-form').hidden = true;
  renderGraph();
});
action('apply-json', 'click', () => assignGraph(jsonFrom('definition')));
action('validate', 'click', async () => {
  const result = await request('graph/validate', { definition: graph });
  msg('节点契约通过 · ' + Object.keys(result.connections).join('、'));
});
action('save', 'click', () => save(false));
action('publish', 'click', () => save(true));
action('activate', 'click', async () => {
  const result = await request('flows/' + encodeURIComponent(read('flow')) + '/activate',
    { expected: Number(read('active')), revision: Number(read('revision')) });
  el('active').value = result.active;
  msg('活动修订已切换到 ' + result.active);
});
action('template', 'click', async () => {
  const content = await request('flows/' + encodeURIComponent(read('flow')) + '/templates/' + Number(read('revision')));
  const href = URL.createObjectURL(new Blob([JSON.stringify(content, null, 2)], { type: 'application/json' }));
  const link = document.createElement('a');
  link.href = href;
  link.download = 'workflow-template.json';
  link.click();
  window.setTimeout(() => URL.revokeObjectURL(href), 1000);
});
action('demo-connect', 'click', async () => {
  const active = await request('connections');
  for (const [name, capability, settings] of [['orders-demo', 'orders', {}],
    ['draft-demo', 'model', { fields: ['order_no', 'state'] }], ['inbox-demo', 'inbox', { recipient_domain: 'example.test' }]]) {
    if (!active.some((item) => item.connection === name)) {
      await request('connections/' + name, { capability, settings, expected: 0 });
    }
  }
  await request('orders/ORD-4001', { state: 'shipped', expected: 0 }).catch((error) => {
    if (!error.message.includes('order version changed')) throw error;
  });
  msg('样例连接和订单已就绪');
});
action('trigger', 'click', async () => {
  const result = await request('flows/' + encodeURIComponent(read('flow')) + '/events', { source: 'mailbox', event: jsonFrom('event') });
  msg((result.replayed ? '已有运行 ' : '已入队 ') + result.id);
  if (can('operator', 'author', 'approver')) {
    switchTab(true);
    await runs(true);
    await openRun(result.id);
  }
});
action('refresh', 'click', async () => { await runs(true); if (selectedRun) await openRun(selectedRun.id); });
action('filter', 'change', () => runs(true));
action('older', 'click', () => runs(false));
action('correction', 'submit', async () => {
  await request('runs/' + selectedRun.id + '/correction', { revision: selectedRun.revision, order_no: read('order-no') });
  await openRun(selectedRun.id);
});
action('revise', 'click', () => review('revise', { changes: { recipient: read('recipient'), body: read('reply') } }));
action('regenerate', 'click', () => review('revise', { changes: { regenerate: true } }));
action('approve', 'click', () => review('approve', {}));
action('reject', 'click', () => review('reject', {}));
action('reconcile', 'click', async () => {
  await request('deliveries/' + selectedDelivery.operation + '/reconcile', {});
  await openRun(selectedRun.id);
});
action('inbox', 'click', async () => { write('inbox-items', await request('inbox')); el('inbox-items').hidden = false; });
action('stats', 'click', async () => { write('metrics', await request('statistics')); el('metrics').hidden = false; });
