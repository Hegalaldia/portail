#!/usr/bin/env python3
"""
Serveur local pour la heatmap animaux sauvages.
Lance avec : python3 server.py
Puis ouvre : http://localhost:8080  (ou http://[IP]:8080 depuis un autre ordi)
"""
import http.server, urllib.request, json, os, threading, time, re

PORT     = 8080
SHEET_ID = '1VpN669xp2u34Itapwz5UWuEIi2UmoxTTWfrPQP83rhE'
CSV_URL  = f'https://docs.google.com/spreadsheets/d/{SHEET_ID}/export?format=csv'

# Cache en mémoire : évite de recharger le sheet à chaque requête
_cache = {'data': None, 'ts': 0}
CACHE_TTL = 300  # secondes (5 min)

def fetch_sheet_csv():
    """Télécharge le CSV depuis Google Sheets avec suivi de redirection."""
    req = urllib.request.Request(CSV_URL, headers={'User-Agent': 'heatmap-server/1.0'})
    with urllib.request.urlopen(req, timeout=15) as r:
        return r.read().decode('utf-8')

def get_data():
    now = time.time()
    if _cache['data'] is None or now - _cache['ts'] > CACHE_TTL:
        print('Chargement du Google Sheet...')
        _cache['data'] = fetch_sheet_csv()
        _cache['ts']   = now
        print(f'  OK — {len(_cache["data"].splitlines())} lignes')
    return _cache['data']

HTML_FILE = 'heatmap.html'
GEO_LOCK  = threading.Lock()

def add_to_geo_preloaded(ville, lat, lon):
    """Ajoute une ville dans GEO_PRELOADED directement dans le fichier HTML."""
    with GEO_LOCK:
        with open(HTML_FILE, 'r', encoding='utf-8') as f:
            html = f.read()

        # Extraire le JSON actuel de GEO_PRELOADED
        m = re.search(r'const GEO_PRELOADED = (\{.*?\});', html, re.DOTALL)
        if not m:
            return False, 'GEO_PRELOADED introuvable dans le HTML'

        geo = json.loads(m.group(1))
        if ville in geo:
            return True, 'déjà présent'  # rien à faire

        geo[ville] = {'lat': lat, 'lon': lon}

        # Réécrire le JSON sur une seule ligne compacte
        new_json  = json.dumps(geo, ensure_ascii=False, separators=(',', ':'))
        new_html  = html[:m.start(1)] + new_json + html[m.end(1):]

        with open(HTML_FILE, 'w', encoding='utf-8') as f:
            f.write(new_html)

        print(f'[geocache] ✓ Ajouté : {ville} ({lat}, {lon})')
        return True, 'ajouté'


class Handler(http.server.SimpleHTTPRequestHandler):
    def do_GET(self):
        if self.path == '/api/data':
            try:
                csv = get_data()
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
    except:
        local_ip = '127.0.0.1'

    print(f'\n🗺️  Heatmap Animaux Sauvages')
    print(f'   http://localhost:{PORT}')
    print(f'   http://{local_ip}:{PORT}  ← partager cette adresse\n')

    with http.server.ThreadingHTTPServer(('', PORT), Handler) as srv:
        try:
            srv.serve_forever()
        except KeyboardInterrupt:
            print('\nServeur arrêté.')
