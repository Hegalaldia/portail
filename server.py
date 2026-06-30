#!/usr/bin/env python3
"""
Serveur local pour la heatmap animaux sauvages.
Lance avec : python3 server.py
Puis ouvre : http://localhost:8080  (ou http://[IP]:8080 depuis un autre ordi)
"""
import http.server, urllib.request, json, os, threading, time, re

PORT             = 8080
DEFAULT_SHEET_ID = '1VpN669xp2u34Itapwz5UWuEIi2UmoxTTWfrPQP83rhE'
CONFIG_FILE      = 'config.json'

# ── Configuration persistante (config.json) ────────────────────────────────────
_cfg_lock = threading.Lock()

def load_config():
    if os.path.exists(CONFIG_FILE):
        try:
            with open(CONFIG_FILE, encoding='utf-8') as f:
                return json.load(f)
        except Exception:
            pass
    return {}

def save_config(cfg):
    with open(CONFIG_FILE, 'w', encoding='utf-8') as f:
        json.dump(cfg, f, ensure_ascii=False, indent=2)

def get_sheet_id():
    return load_config().get('sheet_id', DEFAULT_SHEET_ID)

def set_sheet_id(new_id):
    with _cfg_lock:
        cfg = load_config()
        cfg['sheet_id'] = new_id
        save_config(cfg)
        # Invalider le cache pour forcer un rechargement
        _cache['data'] = None
        _cache['ts']   = 0
    print(f'[config] ✓ Sheet ID mis à jour : {new_id}')

# ── Cache données ──────────────────────────────────────────────────────────────
_cache = {'data': None, 'ts': 0}
CACHE_TTL = 300  # secondes (5 min)

def fetch_csv_tab(sheet_id, sheet_name=None):
    """Télécharge un onglet CSV depuis Google Sheets."""
    from urllib.parse import quote
    if sheet_name:
        url = f'https://docs.google.com/spreadsheets/d/{sheet_id}/gviz/tq?tqx=out:csv&sheet={quote(sheet_name)}'
    else:
        url = f'https://docs.google.com/spreadsheets/d/{sheet_id}/export?format=csv'
    req = urllib.request.Request(url, headers={'User-Agent': 'heatmap-server/1.0'})
    with urllib.request.urlopen(req, timeout=15) as r:
        return r.read().decode('utf-8')

def get_data():
    now = time.time()
    if _cache['data'] is None or now - _cache['ts'] > CACHE_TTL:
        print('Chargement du Google Sheet...')
        _cache['data'] = fetch_csv_tab(get_sheet_id())
        _cache['ts']   = now
        print(f'  OK — {len(_cache["data"].splitlines())} lignes')
    return _cache['data']

_villes_cache = {'data': None, 'ts': 0}

def get_villes():
    now = time.time()
    if _villes_cache['data'] is None or now - _villes_cache['ts'] > CACHE_TTL:
        print('Chargement onglet Ville...')
        csv = fetch_csv_tab(get_sheet_id(), 'Ville')
        # Extraire uniquement les noms de communes (colonne "Ville de decouverte")
        import csv as csvmod, io
        reader = csvmod.DictReader(io.StringIO(csv))
        villes = sorted(set(
            r['Ville de decouverte'].strip()
            for r in reader
            if r.get('Ville de decouverte', '').strip()
        ))
        _villes_cache['data'] = villes
        _villes_cache['ts']   = now
        print(f'  OK — {len(villes)} communes')
    return _villes_cache['data']

# ── Géocache HTML ──────────────────────────────────────────────────────────────
HTML_FILE = 'heatmap.html'
GEO_LOCK  = threading.Lock()

def add_to_geo_preloaded(ville, lat, lon):
    """Ajoute une ville dans GEO_PRELOADED directement dans le fichier HTML."""
    with GEO_LOCK:
        with open(HTML_FILE, 'r', encoding='utf-8') as f:
            html = f.read()

        m = re.search(r'const GEO_PRELOADED = (\{.*?\});', html, re.DOTALL)
        if not m:
            return False, 'GEO_PRELOADED introuvable dans le HTML'

        geo = json.loads(m.group(1))
        if ville in geo:
            return True, 'déjà présent'

        geo[ville] = {'lat': lat, 'lon': lon}
        new_json  = json.dumps(geo, ensure_ascii=False, separators=(',', ':'))
        new_html  = html[:m.start(1)] + new_json + html[m.end(1):]

        with open(HTML_FILE, 'w', encoding='utf-8') as f:
            f.write(new_html)

        print(f'[geocache] ✓ Ajouté : {ville} ({lat}, {lon})')
        return True, 'ajouté'


# ── Serveur HTTP ───────────────────────────────────────────────────────────────
class Handler(http.server.SimpleHTTPRequestHandler):

    def do_GET(self):
        if self.path == '/api/data':
            try:
                csv  = get_data()
                body = csv.encode('utf-8')
                self.send_response(200)
                self.send_header('Content-Type', 'text/csv; charset=utf-8')
                self.send_header('Content-Length', len(body))
                self.send_header('Access-Control-Allow-Origin', '*')
                self.end_headers()
                self.wfile.write(body)
            except Exception as e:
                self.send_response(502)
                self.send_header('Content-Type', 'text/plain')
                self.end_headers()
                self.wfile.write(str(e).encode())

        elif self.path == '/api/villes':
            try:
                villes = get_villes()
                body = json.dumps(villes, ensure_ascii=False).encode('utf-8')
                self.send_response(200)
                self.send_header('Content-Type', 'application/json; charset=utf-8')
                self.send_header('Content-Length', len(body))
                self.send_header('Access-Control-Allow-Origin', '*')
                self.end_headers()
                self.wfile.write(body)
            except Exception as e:
                self.send_response(502)
                self.send_header('Content-Type', 'text/plain')
                self.end_headers()
                self.wfile.write(str(e).encode())

        elif self.path == '/api/config':
            body = json.dumps({
                'sheet_id': get_sheet_id()
            }).encode('utf-8')
            self.send_response(200)
            self.send_header('Content-Type', 'application/json')
            self.send_header('Content-Length', len(body))
            self.send_header('Access-Control-Allow-Origin', '*')
            self.end_headers()
            self.wfile.write(body)

        else:
            super().do_GET()

    def do_POST(self):
        if self.path == '/api/geocache':
            try:
                length = int(self.headers.get('Content-Length', 0))
                body   = json.loads(self.rfile.read(length))
                ville  = str(body['ville']).strip()
                lat    = float(body['lat'])
                lon    = float(body['lon'])
                ok, msg = add_to_geo_preloaded(ville, lat, lon)
                resp = json.dumps({'ok': ok, 'msg': msg}).encode()
                self.send_response(200 if ok else 400)
                self.send_header('Content-Type', 'application/json')
                self.send_header('Access-Control-Allow-Origin', '*')
                self.end_headers()
                self.wfile.write(resp)
            except Exception as e:
                self.send_response(500)
                self.send_header('Content-Type', 'text/plain')
                self.end_headers()
                self.wfile.write(str(e).encode())

        elif self.path == '/api/config':
            try:
                length   = int(self.headers.get('Content-Length', 0))
                body     = json.loads(self.rfile.read(length))
                new_id   = str(body.get('sheet_id', '')).strip()
                if not new_id:
                    raise ValueError('sheet_id manquant')
                set_sheet_id(new_id)
                resp = json.dumps({'ok': True, 'sheet_id': new_id}).encode()
                self.send_response(200)
                self.send_header('Content-Type', 'application/json')
                self.send_header('Access-Control-Allow-Origin', '*')
                self.end_headers()
                self.wfile.write(resp)
            except Exception as e:
                self.send_response(400)
                self.send_header('Content-Type', 'text/plain')
                self.end_headers()
                self.wfile.write(str(e).encode())

        else:
            self.send_response(404)
            self.end_headers()

    def log_message(self, fmt, *args):
        if '/api/' in args[0]:
            print(f'[{args[1]}] {args[0]}')


if __name__ == '__main__':
    os.chdir(os.path.dirname(os.path.abspath(__file__)))
    # Pré-chargement au démarrage
    threading.Thread(target=get_data, daemon=True).start()

    import socket
    hostname = socket.gethostname()
    try:
        local_ip = socket.gethostbyname(hostname)
    except Exception:
        local_ip = '127.0.0.1'

    sheet_id = get_sheet_id()
    print(f'\n🗺️  Heatmap Animaux Sauvages')
    print(f'   http://localhost:{PORT}')
    print(f'   http://{local_ip}:{PORT}  ← partager cette adresse')
    print(f'   Sheet ID : {sheet_id}\n')

    with http.server.ThreadingHTTPServer(('', PORT), Handler) as srv:
        try:
            srv.serve_forever()
        except KeyboardInterrupt:
            print('\nServeur arrêté.')
