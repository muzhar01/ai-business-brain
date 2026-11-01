// Load test: k6 run -e BASE=https://your-app -e TOKEN=brn_... deploy/loadtest/k6.js
// Uses the fake/cheap provider in staging so you measure the platform, not the LLM vendor.
import http from 'k6/http';
import { check, sleep } from 'k6';

export const options = {
  scenarios: {
    api_reads: { executor: 'ramping-arrival-rate', startRate: 50, timeUnit: '1s', preAllocatedVUs: 500, maxVUs: 5000,
      stages: [{ target: 1000, duration: '2m' }, { target: 5000, duration: '5m' }, { target: 0, duration: '1m' }], exec: 'reads' },
    chat: { executor: 'constant-arrival-rate', rate: 200, timeUnit: '1s', duration: '8m', preAllocatedVUs: 500, maxVUs: 3000, exec: 'chat' },
  },
  thresholds: { http_req_failed: ['rate<0.001'], 'http_req_duration{scenario:api_reads}': ['p(95)<150', 'p(99)<400'] },
};

const H = { headers: { Authorization: `Bearer ${__ENV.TOKEN}`, 'Content-Type': 'application/json', Accept: 'application/json' } };

export function reads() {
  const r = http.get(`${__ENV.BASE}/api/brain/leads`, H);
  check(r, { '200': (x) => x.status === 200 });
}

export function chat() {
  const r = http.post(`${__ENV.BASE}/api/brain/chat`, JSON.stringify({ message: 'What are our opening hours?' }), H);
  check(r, { 'answered': (x) => x.status === 200 });
  sleep(1);
}
