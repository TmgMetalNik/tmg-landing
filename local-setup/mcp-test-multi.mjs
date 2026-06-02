import { spawn } from 'node:child_process';

// config: { name, command, args, env, calls: [{tool, arguments}] }  (calls optional)
const cfg = JSON.parse(process.argv[2]);

const child = spawn(cfg.command, cfg.args, {
  env: { ...process.env, ...(cfg.env || {}) },
  shell: process.platform === 'win32',
  stdio: ['pipe', 'pipe', 'pipe'],
});

let buf = '', stderr = '';
const pending = new Map();
let idc = 0;

function send(method, params, isNotification = false) {
  const msg = { jsonrpc: '2.0', method, params };
  if (!isNotification) msg.id = ++idc;
  child.stdin.write(JSON.stringify(msg) + '\n');
  if (!isNotification) {
    return new Promise((res, rej) => {
      pending.set(msg.id, { res, rej });
      setTimeout(() => { if (pending.has(msg.id)) { pending.delete(msg.id); rej(new Error('timeout ' + method)); } }, 90000);
    });
  }
}

child.stdout.on('data', (d) => {
  buf += d.toString();
  let i;
  while ((i = buf.indexOf('\n')) >= 0) {
    const line = buf.slice(0, i).trim();
    buf = buf.slice(i + 1);
    if (!line) continue;
    let msg; try { msg = JSON.parse(line); } catch { continue; }
    if (msg.id && pending.has(msg.id)) {
      const p = pending.get(msg.id); pending.delete(msg.id);
      if (msg.error) p.rej(new Error(JSON.stringify(msg.error))); else p.res(msg.result);
    }
  }
});
child.stderr.on('data', (d) => { stderr += d.toString(); });

function done(ok, payload) {
  console.log('RESULT_JSON::' + JSON.stringify({ name: cfg.name, ok, payload, stderr: stderr.slice(-500) }));
  child.kill(); process.exit(0);
}

(async () => {
  try {
    await send('initialize', { protocolVersion: '2024-11-05', capabilities: {}, clientInfo: { name: 'mcp-test', version: '1.0' } });
    send('notifications/initialized', {}, true);
    const tools = await send('tools/list', {});
    const toolNames = (tools.tools || []).map(t => t.name);
    const callResults = [];
    for (const c of (cfg.calls || [])) {
      const r = await send('tools/call', { name: c.tool, arguments: c.arguments || {} });
      let text = ''; if (r && r.content) text = r.content.map(x => x.text || (x.type === 'image' ? '[image '+(x.mimeType||'')+']' : '')).join(' ');
      callResults.push({ tool: c.tool, isError: !!r.isError, preview: text.slice(0, 300) });
    }
    done(true, { tools: toolNames.length, toolNames: toolNames.slice(0, 8), calls: callResults });
  } catch (e) {
    done(false, { error: String(e && e.message || e) });
  }
})();
