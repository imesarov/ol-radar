import base64, json, sys, urllib.request

BASE = "http://127.0.0.1:" + __import__("os").environ.get("WPTEST_PORT", "8089") + "/wp-json/mcp/mcp-adapter-default-server"


class Client:
    def __init__(self, credfile):
        cred = open(credfile).read().strip()
        self.auth = "Basic " + base64.b64encode(cred.encode()).decode()
        self.sid = None
        self.n = 0

    def rpc(self, method, params=None, notify=False):
        body = {"jsonrpc": "2.0", "method": method}
        if params is not None:
            body["params"] = params
        if not notify:
            self.n += 1
            body["id"] = self.n
        req = urllib.request.Request(BASE, data=json.dumps(body).encode(), method="POST")
        req.add_header("Content-Type", "application/json")
        req.add_header("Accept", "application/json, text/event-stream")
        req.add_header("Authorization", self.auth)
        if self.sid:
            req.add_header("Mcp-Session-Id", self.sid)
            req.add_header("MCP-Protocol-Version", "2025-06-18")
        try:
            with urllib.request.urlopen(req, timeout=120) as r:
                sid = r.headers.get("Mcp-Session-Id")
                if sid:
                    self.sid = sid
                raw = r.read().decode()
                status = r.status
        except urllib.error.HTTPError as e:
            raw = e.read().decode()
            status = e.code
        if not raw.strip():
            return status, None
        return status, json.loads(raw)

    def init(self):
        s, r = self.rpc("initialize", {"protocolVersion": "2025-06-18", "capabilities": {}, "clientInfo": {"name": "test", "version": "1"}})
        self.rpc("notifications/initialized", notify=True)
        return s, r

    def call(self, tool, args):
        return self.rpc("tools/call", {"name": tool, "arguments": args})

    def ability(self, name, params=None):
        s, r = self.call("mcp-adapter-execute-ability", {"ability_name": name, "parameters": params or {}})
        return s, r


def payload(r):
    """Izvuci rezultat alata iz MCP odgovora."""
    if r is None:
        return None
    if "error" in r:
        return {"RPC_ERROR": r["error"]}
    res = r.get("result", {})
    if "structuredContent" in res:
        return res["structuredContent"]
    for c in res.get("content", []):
        if c.get("type") == "text":
            try:
                return json.loads(c["text"])
            except Exception:
                return c["text"]
    return res


if __name__ == "__main__":
    c = Client(sys.argv[1])
    print(c.init()[0])
    s, r = c.rpc("tools/list", {})
    print("tools:", [t["name"] for t in r["result"]["tools"]])
