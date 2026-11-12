/* OL Radar na hostingu: zamjena za claude.ai runtime (window.claude.use).
   db → REST dokumenti dodatka (ol-radar/v1), sample → Anthropic API preko servera (ključ ostaje na serveru),
   mcp → nije dostupan (podatke skuplja server). Ubacuje se u app/index.html pri izgradnji (tools/build-hosted.py). */
(function () {
  "use strict";
  const H = window.OLR_HOSTED;
  if (!H) return;
  const enc = encodeURIComponent;

  async function api(path, opt) {
    opt = opt || {};
    const headers = { "X-WP-Nonce": H.nonce };
    if (opt.body !== undefined) headers["Content-Type"] = "application/json";
    let r;
    try {
      r = await fetch(H.rest + path, { method: opt.method || "GET", credentials: "same-origin", headers, body: opt.body !== undefined ? JSON.stringify(opt.body) : undefined, signal: opt.signal });
    } catch (e) {
      if (e && e.name === "AbortError") throw { code: "cancelled", message: "Zaustavljeno." };
      throw { code: "network", message: "Server ne odgovara: " + (e && e.message || e) };
    }
    let j = null; try { j = await r.json(); } catch (e) { /* prazan odgovor */ }
    if (!r.ok) {
      const code = r.status === 401 || r.status === 403 ? "session_expired" : r.status === 429 ? "rate_limited" : (j && j.code) || ("http_" + r.status);
      throw { code, status: r.status, message: (j && j.message) || ("HTTP " + r.status) };
    }
    return j;
  }
  H.api = api;

  /* ---------- db: isti oblik kao baza artefakta ---------- */
  const listeners = new Map();
  const snap = items => ({ docs: items.map(x => ({ id: x.id, exists: true, data: () => x.data })), size: items.length, empty: !items.length });
  function query(coll, q) {
    return {
      orderBy(field, dir) { return query(coll, Object.assign({}, q, { orderBy: field, dir: dir || "asc" })); },
      limit(n) { return query(coll, Object.assign({}, q, { limit: n })); },
      async get() {
        const p = new URLSearchParams({ orderBy: q.orderBy || "", dir: q.dir || "asc", limit: String(q.limit || 1000) });
        const j = await api("docs/" + enc(coll) + "?" + p.toString());
        return snap(j.items || []);
      },
      onSnapshot(cb, err) {
        const fire = () => this.get().then(cb, err || (() => {}));
        const list = listeners.get(coll) || []; list.push(fire); listeners.set(coll, list);
        fire();
        return () => { const l = listeners.get(coll) || []; listeners.set(coll, l.filter(f => f !== fire)); };
      }
    };
  }
  const notify = coll => (listeners.get(coll) || []).forEach(f => f());
  const db = {
    doc(path) {
      const i = String(path).indexOf("/");
      const coll = path.slice(0, i), id = path.slice(i + 1);
      const url = "doc/" + enc(coll) + "/" + enc(id);
      return {
        id,
        async get() {
          const j = await api(url);
          return j && j.exists ? { id, exists: true, data: () => j.data } : { id, exists: false, data: () => undefined };
        },
        async set(data) { await api(url, { method: "PUT", body: { data } }); notify(coll); },
        async delete() { await api(url, { method: "DELETE" }); notify(coll); }
      };
    },
    collection(coll) { return query(coll, {}); }
  };

  /* ---------- sample: Claude preko Anthropic API-ja, alati se izvršavaju u pregledniku ---------- */
  async function sample(input, opts) {
    opts = opts || {};
    const msgs = typeof input === "string" ? [{ role: "user", content: input }] : input.map(m => ({ role: m.role, content: m.content }));
    const tools = (opts.tools || []).map(t => ({ name: t.name, description: t.description || "", input_schema: t.inputSchema || { type: "object", properties: {} } }));
    let text = "";
    for (let step = 0; step < 8; step++) {
      const r = await api("ai", { method: "POST", body: { messages: msgs, tools, max_tokens: opts.maxTokens || 2500 }, signal: opts.signal });
      const blocks = r.content || [];
      const t = blocks.filter(b => b.type === "text").map(b => b.text).join("");
      if (t) { text += (text ? "\n\n" : "") + t; if (opts.onText) opts.onText({ text }); }
      const uses = blocks.filter(b => b.type === "tool_use");
      if (r.stop_reason !== "tool_use" || !uses.length) break;
      msgs.push({ role: "assistant", content: blocks });
      const results = [];
      for (const u of uses) {
        const tool = (opts.tools || []).find(x => x.name === u.name);
        let out, isErr = false;
        try { out = tool ? await tool.execute(u.input || {}) : "Nepoznat alat: " + u.name; }
        catch (e) { out = String((e && e.message) || e); isErr = true; }
        results.push({ type: "tool_result", tool_use_id: u.id, content: (typeof out === "string" ? out : JSON.stringify(out)).slice(0, 60000), is_error: isErr });
      }
      msgs.push({ role: "user", content: results });
    }
    return { text };
  }
  /* Prvi uravnoteženi JSON objekt ili niz u tekstu (ograda koda i tekst oko njega se zanemaruju). */
  function looseJson(text) {
    const t = String(text || "").replace(/```(?:json)?/gi, "");
    const start = t.search(/[{[]/);
    if (start < 0) return undefined;
    let depth = 0, inStr = false, esc = false;
    for (let i = start; i < t.length; i++) {
      const c = t[i];
      if (inStr) { if (esc) esc = false; else if (c === "\\") esc = true; else if (c === '"') inStr = false; continue; }
      if (c === '"') inStr = true;
      else if (c === "{" || c === "[") depth++;
      else if (c === "}" || c === "]") { depth--; if (depth === 0) { try { return JSON.parse(t.slice(start, i + 1)); } catch (e) { return undefined; } } }
    }
    return undefined;
  }
  /* JSON odgovor. Claude odgovara pozivom alata „odgovor”, pa je odgovor uvijek strukturiran (nema teksta oko
     JSON-a ni ograde koda). Ako je odgovor prekinut zbog duljine ili je prazan, ide jedan sažetiji pokušaj.
     Ako API odbije poziv alata, radi rezervni način s JSON-om u tekstu. */
  sample.json = async function (prompt, opts) {
    opts = opts || {};
    const schema = opts.schema && opts.schema.type === "object" ? opts.schema : { type: "object", additionalProperties: true };
    const tool = { name: "odgovor", description: "Sprema traženi odgovor. Polja alata su polja JSON objekta opisanog u zadatku.", input_schema: schema };
    const SHORT = "\n\nVAŽNO: prethodni odgovor je bio predug i prekinut je. Budi sažet: svako tekstualno polje najviše dvije rečenice, najviše 5 koraka i najviše 3 gotova teksta.";
    const ask = async (extra, useTool) => {
      const body = { messages: [{ role: "user", content: prompt + extra + (useTool ? "\n\nOdgovori isključivo pozivom alata odgovor, točno jednom." : "\n\nOdgovori isključivo valjanim JSON-om, bez teksta prije ili poslije i bez ograde koda.") }], max_tokens: opts.maxTokens || 4096 };
      if (useTool) { body.tools = [tool]; body.tool_choice = { name: "odgovor" }; }
      const r = await api("ai", { method: "POST", body, signal: opts.signal });
      const blocks = r.content || [];
      const use = blocks.find(b => b.type === "tool_use" && b.name === "odgovor");
      let val = use && use.input && typeof use.input === "object" && Object.keys(use.input).length ? use.input : undefined;
      if (val === undefined) val = looseJson(blocks.filter(b => b.type === "text").map(b => b.text).join(""));
      return { val, cut: r.stop_reason === "max_tokens" };
    };
    let useTool = true, first;
    try { first = await ask("", true); }
    catch (e) {
      // samo ako je API odbio sam zahtjev (npr. model ne podržava prisilni poziv alata); ostale greške idu dalje
      if (!e || e.code !== "olr_ai_zahtjev") throw e;
      useTool = false; first = await ask("", false);
    }
    if (first.val !== undefined && !first.cut) return first.val;
    const second = await ask(SHORT, useTool);
    if (second.val !== undefined) return second.val;
    if (first.val !== undefined) return first.val; // prekinut, ali čitljiv: bolje nepotpun odgovor nego nikakav
    throw { code: "invalid_json", cut: first.cut || second.cut, message: first.cut || second.cut ? "Odgovor je prekinut zbog duljine" : "Odgovor nije u očekivanom obliku" };
  };
  sample.limits = async () => ({ tools: { maxCount: 20 } }); // server prihvaća do 20 alata po zahtjevu

  window.claude = {
    use: async name => name === "db" ? db : name === "sample" ? (H.ai ? sample : null) : null
  };

  /* Ploča je u okviru unutar wp-admina: odabrani modul (#pregled, #pozicije …) prepisuje se u adresu
     glavnog prozora, pa osvježavanje i oznake vode na isti modul. */
  if (window.parent !== window) {
    document.documentElement.classList.add("ugradeno");
    try {
      const ph = window.parent.location.hash;
      if (ph && !location.hash) history.replaceState(null, "", location.pathname + location.search + ph);
    } catch (e) { /* drugi izvor */ }
    const sync = () => {
      try {
        const p = window.parent.location;
        if (p.hash !== location.hash) window.parent.history.replaceState(null, "", p.pathname + p.search + location.hash);
      } catch (e) { /* drugi izvor: ništa */ }
    };
    window.addEventListener("hashchange", sync);
    sync();
  }
})();
