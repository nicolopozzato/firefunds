"""Takes the README screenshots of a seeded demo instance (seed.py).
Runs inside the Playwright image, see run.sh."""
import json
import sys
import urllib.request

from playwright.sync_api import sync_playwright

BASE = sys.argv[1].rstrip('/')
OUT = sys.argv[2]
EMAIL, PASSWORD = 'demo@example.com', 'Demo-password-123'


def api(method, path, token, body):
    req = urllib.request.Request(BASE + '/api' + path, method=method, data=json.dumps(body).encode())
    req.add_header('Content-Type', 'application/json')
    req.add_header('Authorization', 'Bearer ' + token)
    urllib.request.urlopen(req).read()


def set_theme(theme):
    req = urllib.request.Request(BASE + '/api/auth/login', data=json.dumps({'email': EMAIL, 'password': PASSWORD}).encode())
    req.add_header('Content-Type', 'application/json')
    token = json.loads(urllib.request.urlopen(req).read())['token']
    api('PUT', '/auth/me/preferences', token, {
        'locale': 'en', 'theme': theme, 'pageSize': 25, 'timezone': 'Europe/Rome', 'numberFormat': 'en-US'})


def session(browser, **context):
    ctx = browser.new_context(locale='en-US', timezone_id='Europe/Rome', **context)
    # The whole current year on the dashboard, instead of the month just started.
    ctx.add_init_script("localStorage.setItem('firefunds-timeframe', JSON.stringify({preset: 'currentYear'}));"
                        "localStorage.setItem('i18nextLng', 'en');")
    page = ctx.new_page()
    page.goto(BASE + '/login')
    page.fill('input[type=email]', EMAIL)
    page.fill('input[type=password]', PASSWORD)
    page.click('button[type=submit]')
    page.wait_for_url('**/dashboard')
    return ctx, page


def shot(page, path, name, full_page=False):
    page.goto(BASE + path)
    page.wait_for_load_state('networkidle')
    # Bottom-right corner: no hover tooltip left open, and not over the
    # sidebar, whose collapse button shows on hover.
    viewport = page.viewport_size
    page.mouse.move(viewport['width'] - 1, viewport['height'] - 1)
    page.wait_for_timeout(1500)  # chart animations
    page.screenshot(path=f'{OUT}/{name}.png', full_page=full_page)
    print('saved', name)


with sync_playwright() as p:
    browser = p.chromium.launch()
    desktop = dict(viewport={'width': 1440, 'height': 900}, device_scale_factor=1)
    phone = dict(viewport={'width': 390, 'height': 844}, device_scale_factor=2, is_mobile=True, has_touch=True)

    set_theme('light')
    ctx, page = session(browser, **desktop)
    shot(page, '/dashboard', 'dashboard-light')
    shot(page, '/investments/overview', 'investments')
    shot(page, '/reports/income-expenses', 'report-income-expenses')
    shot(page, '/reports/net-worth', 'report-net-worth')
    ctx.close()

    ctx, page = session(browser, **phone)
    shot(page, '/dashboard', 'mobile-dashboard')
    shot(page, '/transactions', 'mobile-transactions')
    shot(page, '/investments/overview', 'mobile-investments')
    ctx.close()

    set_theme('dark')
    ctx, page = session(browser, **desktop)
    shot(page, '/dashboard', 'dashboard-dark')
    ctx.close()
    browser.close()
