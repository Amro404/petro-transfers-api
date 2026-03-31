import http from "k6/http";
import { check, sleep } from "k6";
import { Counter, Rate, Trend } from "k6/metrics";

function uuidv4() {
  const hex = "0123456789abcdef";
  let id = "";
  for (let i = 0; i < 36; i++) {
    if (i === 8 || i === 13 || i === 18 || i === 23) {
      id += "-";
    } else if (i === 14) {
      id += "4";
    } else if (i === 19) {
      id += hex[(Math.random() * 4) | 8];
    } else {
      id += hex[(Math.random() * 16) | 0];
    }
  }
  return id;
}

const insertedCounter = new Counter("events_inserted");
const duplicatesCounter = new Counter("events_duplicates");
const invalidCounter = new Counter("events_invalid");
const failedCounter = new Counter("events_failed");
const successRate = new Rate("success_rate");
const batchDuration = new Trend("batch_duration_ms");

const BASE_URL = __ENV.BASE_URL || "http://localhost:8000";
const BATCH_SIZE = parseInt(__ENV.BATCH_SIZE || "100", 10);
const STATIONS = parseInt(__ENV.STATIONS || "100", 10);
const STATUSES = ["approved", "declined", "pending"];

export const options = {
  scenarios: {
    ramp_to_peak: {
      executor: "ramping-vus",
      startVUs: 0,
      stages: [
        { duration: "30s", target: 100 },
        { duration: "1m", target: 300 },
        { duration: "2m", target: 500 },
        { duration: "4m", target: 500 },
        { duration: "30s", target: 0 },
      ],
    },
  },
  thresholds: {
    http_req_failed: ["rate<0.05"],
    http_req_duration: ["p(95)<5000"],
    success_rate: ["rate>0.95"],
  },
};

function buildBatch(size) {
  const events = [];
  for (let i = 0; i < size; i++) {
    events.push({
      event_id: uuidv4(),
      station_id: `station-${Math.floor(Math.random() * STATIONS) + 1}`,
      amount: parseFloat((Math.random() * 1000).toFixed(2)),
      status: STATUSES[Math.floor(Math.random() * STATUSES.length)],
      created_at: new Date().toISOString(),
    });
  }
  return events;
}

export default function () {
  const payload = JSON.stringify({ events: buildBatch(BATCH_SIZE) });

  const res = http.post(`${BASE_URL}/api/transfers`, payload, {
    headers: { "Content-Type": "application/json", Accept: "application/json" },
    timeout: "30s",
  });

  const ok = check(res, {
    "status is 200": (r) => r.status === 200,
    "has inserted field": (r) => {
      try {
        return JSON.parse(r.body).inserted !== undefined;
      } catch {
        return false;
      }
    },
  });

  successRate.add(ok);
  batchDuration.add(res.timings.duration);

  if (res.status === 200) {
    try {
      const body = JSON.parse(res.body);
      insertedCounter.add(body.inserted || 0);
      duplicatesCounter.add(body.duplicates || 0);
      invalidCounter.add(body.invalid || 0);
      failedCounter.add(body.failed || 0);
    } catch (_) {}
  }

  sleep(0.1);
}

export function handleSummary(data) {
  const totalReqs = data.metrics.http_reqs ? data.metrics.http_reqs.values.count : 0;
  const totalEvents = totalReqs * BATCH_SIZE;
  const inserted = data.metrics.events_inserted
    ? data.metrics.events_inserted.values.count
    : 0;

  const summary = `
========================================
  K6 LOAD TEST SUMMARY
========================================
  Total HTTP requests:  ${totalReqs}
  Total events sent:    ${totalEvents}
  Events inserted:      ${inserted}
  Events duplicates:    ${data.metrics.events_duplicates ? data.metrics.events_duplicates.values.count : 0}
  Events invalid:       ${data.metrics.events_invalid ? data.metrics.events_invalid.values.count : 0}
  Events failed:        ${data.metrics.events_failed ? data.metrics.events_failed.values.count : 0}
  Avg response time:    ${data.metrics.http_req_duration ? data.metrics.http_req_duration.values.avg.toFixed(2) : "N/A"} ms
  p95 response time:    ${data.metrics.http_req_duration ? data.metrics.http_req_duration.values["p(95)"].toFixed(2) : "N/A"} ms
  Success rate:         ${data.metrics.success_rate ? (data.metrics.success_rate.values.rate * 100).toFixed(2) : "N/A"}%
========================================
`;

  return {
    stdout: summary,
    "k6/results.json": JSON.stringify(data, null, 2),
  };
}
