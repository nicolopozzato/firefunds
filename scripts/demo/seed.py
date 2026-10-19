#!/usr/bin/env python3
"""Fills an EMPTY FireFunds instance with a fictional user and a year of data,
through the API only (so every record is valid as the app sees it). Used for
the README screenshots (scripts/demo/run.sh); never point it at real data.

    python3 scripts/demo/seed.py http://127.0.0.1:8099

Investment prices come from Yahoo Finance (the instance needs internet).
"""
import json
import random
import sys
import urllib.error
import urllib.request
from datetime import date, datetime, timedelta

BASE = (sys.argv[1] if len(sys.argv) > 1 else 'http://127.0.0.1:8099').rstrip('/')
EMAIL = 'demo@example.com'
PASSWORD = 'Demo-password-123'
TODAY = date.today()
START = date(TODAY.year - 1, TODAY.month, 1)

random.seed(42)
token = None


def call(method, path, body=None):
    req = urllib.request.Request(BASE + '/api' + path, method=method)
    req.add_header('Content-Type', 'application/json')
    req.add_header('Accept', 'application/json')
    if token:
        req.add_header('Authorization', 'Bearer ' + token)
    data = json.dumps(body).encode() if body is not None else None
    try:
        with urllib.request.urlopen(req, data, timeout=120) as res:
            raw = res.read()
            return json.loads(raw) if raw else None
    except urllib.error.HTTPError as e:
        sys.exit(f'{method} {path} -> {e.code}: {e.read().decode()[:500]}')


def at(day, hour=12, minute=0):
    return datetime(day.year, day.month, day.day, hour, minute).strftime('%Y-%m-%dT%H:%M:00+01:00')


def months():
    d = START
    while d <= TODAY:
        yield d
        d = date(d.year + (d.month == 12), d.month % 12 + 1, 1)


def day_in(month, day):
    """A day of `month`, or None if it is in the future."""
    last = (date(month.year + (month.month == 12), month.month % 12 + 1, 1) - timedelta(days=1)).day
    d = date(month.year, month.month, min(day, last))
    return d if d <= TODAY else None


# --- user ---------------------------------------------------------------------
call('POST', '/auth/register', {'email': EMAIL, 'password': PASSWORD, 'firstName': 'Alex', 'lastName': 'Demo'})
token = call('POST', '/auth/login', {'email': EMAIL, 'password': PASSWORD})['token']
call('PUT', '/auth/me/preferences', {
    'locale': 'en', 'theme': 'light', 'pageSize': 25, 'timezone': 'Europe/Rome', 'numberFormat': 'en-US',
})

# --- accounts -----------------------------------------------------------------
def account(kind, name, **extra):
    return call('POST', f'/accounts/{kind}', {'type': kind, 'name': name, **extra})['id']

opening = {'openingBalanceDate': START.isoformat(), 'currencyCode': 'EUR'}
checking = account('asset', 'Everyday Bank', assetKind='checking', openingBalance='2850.00',
                   iban='IT60X0542811101000000123456', **opening)
savings = account('asset', 'Savings', assetKind='checking', openingBalance='6000.00', **opening)
broker = account('asset', 'Broker', assetKind='investment', openingBalance='0', **opening)

shops = {n: account('expense', n) for n in [
    'Supermarket', 'Landlord', 'Energy & water', 'Internet & phone', 'Restaurants',
    'Online shop', 'Pharmacy', 'Gym', 'Fuel station', 'Cinema', 'Airline', 'Hotel']}
employer = account('revenue', 'Employer')
refunds = account('revenue', 'Refunds')

# --- categories and tags ------------------------------------------------------
cat = {n: call('POST', '/categories', {'name': n})['id'] for n in [
    'Groceries', 'Housing', 'Utilities', 'Eating out', 'Shopping', 'Health', 'Sport',
    'Transport', 'Leisure', 'Travel', 'Salary']}
holiday = call('POST', '/tags', {'name': 'Holiday'})['id']


def tx(source, dest, day, amount, description, category=None, tags=(), hour=12):
    call('POST', '/transactions', {
        'description': description, 'sourceAccountId': source, 'destinationAccountId': dest,
        'occurredAt': at(day, hour, random.randint(0, 59)), 'amount': f'{amount:.2f}',
        'categoryId': category, 'tagIds': list(tags),
    })


def spend(day, shop, amount, description, category, tags=()):
    if day:
        tx(checking, shops[shop], day, amount, description, cat[category], tags, hour=random.randint(9, 21))

# --- a year of money ----------------------------------------------------------
for m in months():
    if d := day_in(m, 27):
        tx(employer, checking, d, 2450 + (900 if m.month == 12 else 0), 'Salary', cat['Salary'], hour=9)
    if d := day_in(m, 2):
        tx(checking, shops['Landlord'], d, 780, 'Rent', cat['Housing'], hour=10)
    spend(day_in(m, 8), 'Energy & water', random.uniform(70, 145), 'Energy and water bill', 'Utilities')
    spend(day_in(m, 15), 'Internet & phone', 29.90, 'Fibre and mobile', 'Utilities')
    spend(day_in(m, 5), 'Gym', 45, 'Gym membership', 'Sport')
    for day in sorted(random.sample(range(1, 29), 6)):
        spend(day_in(m, day), 'Supermarket', random.uniform(18, 95), 'Groceries', 'Groceries')
    for day in random.sample(range(1, 29), random.randint(2, 4)):
        spend(day_in(m, day), 'Restaurants', random.uniform(14, 62), random.choice(['Dinner out', 'Lunch', 'Pizza with friends']), 'Eating out')
    for day in random.sample(range(1, 29), 2):
        spend(day_in(m, day), 'Fuel station', random.uniform(45, 68), 'Fuel', 'Transport')
    if random.random() < 0.7:
        spend(day_in(m, random.randint(1, 28)), 'Online shop', random.uniform(15, 140), random.choice(['Books', 'Headphones', 'Kitchen stuff', 'Shoes']), 'Shopping')
    if random.random() < 0.4:
        spend(day_in(m, random.randint(1, 28)), 'Pharmacy', random.uniform(8, 35), 'Pharmacy', 'Health')
    if random.random() < 0.5:
        spend(day_in(m, random.randint(1, 28)), 'Cinema', 19, 'Cinema', 'Leisure')
    if m.month == 8:
        spend(day_in(m, 3), 'Airline', 286, 'Flights', 'Travel', [holiday])
        spend(day_in(m, 10), 'Hotel', 640, 'Hotel, 6 nights', 'Travel', [holiday])
        spend(day_in(m, 12), 'Restaurants', 74, 'Dinner by the sea', 'Eating out', [holiday])
    if m.month == 4 and (d := day_in(m, 18)):
        tx(refunds, checking, d, 120, 'Insurance refund', hour=11)
    if d := day_in(m, 28):
        tx(checking, savings, d, 300, 'Monthly savings', hour=8)
        tx(checking, broker, d, 250, 'Monthly investment', hour=8)

# --- investments --------------------------------------------------------------
def instrument(name, ticker, yahoo, isin, rate=None):
    body = {'name': name, 'type': 'etf', 'ticker': ticker, 'yahooTicker': yahoo, 'isin': isin}
    if rate:
        body['capitalGainsTaxRate'] = rate
    iid = call('POST', '/investment-instruments', body)['id']
    call('POST', f'/investment-instruments/{iid}/prices/import', {'from': (START - timedelta(days=10)).isoformat(), 'to': TODAY.isoformat()})
    rows = call('GET', f'/investment-instruments/{iid}/prices?from={(START - timedelta(days=10)).isoformat()}&to={TODAY.isoformat()}')
    return iid, {r['date'][:10]: float(r['price']) for r in rows}


def price_on(prices, day):
    for back in range(10):
        p = prices.get((day - timedelta(days=back)).isoformat())
        if p:
            return p
    sys.exit(f'no price around {day}')


def buy(iid, prices, day, cash):
    price = price_on(prices, day)
    quantity = round(cash / price, 6)
    call('POST', '/investment-transactions', {
        'description': 'Monthly plan', 'accountId': broker, 'instrumentId': iid, 'type': 'buy',
        'quantity': f'{quantity:.6f}', 'unitPrice': f'{price:.4f}', 'fees': '0',
        'occurredAt': at(day, 15), 'amount': f'{quantity * price:.2f}',
    })


vwce, vwce_prices = instrument('Vanguard FTSE All-World UCITS ETF', 'VWCE', 'VWCE.MI', 'IE00BK5BQT80')
xeon, xeon_prices = instrument('Xtrackers II EUR Overnight Rate Swap UCITS ETF', 'XEON', 'XEON.MI', 'LU0290358497', '12.5')
for m in months():
    if d := day_in(m, 29):
        buy(vwce, vwce_prices, d, 240)
first = day_in(list(months())[3], 20)
tx(savings, broker, first, 1500, 'Move to broker', hour=9)
buy(xeon, xeon_prices, first, 1490)

# --- dashboard ----------------------------------------------------------------
call('PUT', '/dashboard/accounts', {'visibleAccountIds': [checking, savings, broker]})
print(f'Seeded {BASE} — log in as {EMAIL} / {PASSWORD}')
