/**
 * Stand-in for AcountPay's public bank-logo and payment-link endpoints.
 * Returns a fixed Estonian (and Finnish / Danish) catalogue so the plugin
 * can be exercised without Token.io or the production banks table.
 */
const http = require('http');

const logo = (id) =>
  `https://placehold.co/64x64/png?text=${encodeURIComponent(id)}`;

const BANKS = {
  EE: [
    { bankId: 'ee-lhv', name: 'LHV Pank', logoUrl: logo('LHV') },
    { bankId: 'ee-swedbank', name: 'Swedbank', logoUrl: logo('Swedbank') },
    { bankId: 'ee-seb', name: 'SEB', logoUrl: logo('SEB') },
    { bankId: 'ee-luminor', name: 'Luminor', logoUrl: logo('Luminor') },
    { bankId: 'ee-coop', name: 'Coop Pank', logoUrl: logo('Coop') },
  ],
  FI: [
    { bankId: 'ngp-okoy', name: 'OP Pohjola', logoUrl: logo('OP') },
    { bankId: 'ngp-ndeafi', name: 'Nordea Bank', logoUrl: logo('Nordea') },
  ],
  DK: [
    { bankId: 'ob-danske-dnk', name: 'Danske Bank', logoUrl: logo('Danske') },
  ],
};

let lastPayment = null;

function send(res, status, body) {
  const json = JSON.stringify(body);
  res.writeHead(status, {
    'Content-Type': 'application/json',
    'Content-Length': Buffer.byteLength(json),
  });
  res.end(json);
}

const server = http.createServer((req, res) => {
  const url = new URL(req.url, 'http://127.0.0.1:8099');

  if (req.method === 'GET' && url.pathname === '/v1/banks/public/logos') {
    const country = (url.searchParams.get('country') || 'FI').toUpperCase().slice(0, 2);
    send(res, 200, {
      country,
      generatedAt: new Date().toISOString(),
      banks: BANKS[country] || [],
    });
    return;
  }

  if (req.method === 'POST' && url.pathname === '/v1/sdk/v2/payment-link') {
    let raw = '';
    req.on('data', (chunk) => {
      raw += chunk;
    });
    req.on('end', () => {
      try {
        lastPayment = JSON.parse(raw);
      } catch (error) {
        send(res, 400, { message: 'invalid json' });
        return;
      }
      send(res, 200, {
        redirectUrl: 'https://pay.example.test/local',
        paymentId: '9001',
        posBankCountryCode: lastPayment.posBankCountryCode || null,
      });
    });
    return;
  }

  if (req.method === 'GET' && url.pathname === '/__last-payment') {
    send(res, 200, lastPayment);
    return;
  }

  send(res, 404, { message: 'not found' });
});

server.listen(8099, '0.0.0.0');
