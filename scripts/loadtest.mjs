/**
 * Load test for the exam engine: N synthetic competitors sitting a test at once.
 *
 * It drives the real student API — availability → start → read the paper →
 * submit — with sessions minted by `php artisan loadtest:students`, one
 * competitor per virtual user, and reports what each step cost.
 *
 * 🪤 It does NOT log in through `/api/student/identify`. That endpoint is capped
 * at eight a minute per IP on purpose, so logging five thousand competitors in
 * from one address is not a load test but a two-week wait. The login is measured
 * separately with `--flow=identify`, in a burst small enough to stay under the cap.
 *
 * 🔴 `start` and `submit` WRITE. Run it only against competitors the fixtures
 * command created, and remove them afterwards with `--cleanup`.
 *
 * Usage (on the server, from the application directory):
 *   node scripts/loadtest.mjs --vus=100 --base=https://staging.soa-htc.com
 *   node scripts/loadtest.mjs --vus=500 --flow=read
 *   node scripts/loadtest.mjs --vus=5000 --file=storage/app/loadtest/loadtest.json
 */

import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import https from 'node:https';
import http from 'node:http';
import { performance } from 'node:perf_hooks';

const args = Object.fromEntries(
    process.argv.slice(2).map((a) => {
        const [k, v = 'true'] = a.replace(/^--/, '').split('=');
        return [k, v];
    })
);

const BASE = (args.base ?? 'https://staging.soa-htc.com').replace(/\/$/, '');
const FILE = args.file ?? 'storage/app/loadtest/loadtest.json';
const VUS = Number(args.vus ?? 100);
const FLOW = args.flow ?? 'full'; // full | read
const OUT = args.out ?? null;
const PASSWORD = args.password ?? null;

const fixtures = JSON.parse(readFileSync(FILE, 'utf8'));
const students = fixtures.students.slice(0, VUS);
const TEST_ID = Number(args.test ?? fixtures.test_id);

if (students.length < VUS) {
    console.error(`Only ${students.length} sessions in ${FILE}, asked for ${VUS}. Create more first.`);
    process.exit(1);
}

/*
 * One agent for the whole run, with a socket ceiling equal to the number of
 * virtual users: the point is that they arrive together, so the driver must not
 * quietly queue them behind a default pool of six.
 */
const isHttps = BASE.startsWith('https://');
const agent = new (isHttps ? https.Agent : http.Agent)({
    keepAlive: true,
    maxSockets: VUS,
    maxFreeSockets: VUS,
    rejectUnauthorized: false,
});

const request = (method, path, token, body) =>
    new Promise((resolve) => {
        const payload = body === undefined ? null : JSON.stringify(body);
        const url = new URL(BASE + path);
        const started = performance.now();

        const req = (isHttps ? https : http).request(
            {
                agent,
                method,
                hostname: url.hostname,
                port: url.port || (isHttps ? 443 : 80),
                path: url.pathname + url.search,
                headers: {
                    Accept: 'application/json',
                    Authorization: `Bearer ${token}`,
                    ...(payload ? { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(payload) } : {}),
                },
            },
            (res) => {
                const chunks = [];
                res.on('data', (c) => chunks.push(c));
                res.on('end', () => {
                    const text = Buffer.concat(chunks).toString('utf8');
                    let json = null;
                    try {
                        json = JSON.parse(text);
                    } catch {
                        /* a 500 comes back as HTML; the status is what matters */
                    }
                    resolve({ status: res.statusCode, ms: performance.now() - started, json, text });
                });
            }
        );

        req.on('error', (e) => resolve({ status: 0, ms: performance.now() - started, json: null, text: e.message }));
        req.setTimeout(120_000, () => {
            req.destroy();
            resolve({ status: 0, ms: performance.now() - started, json: null, text: 'timeout' });
        });

        if (payload) req.write(payload);
        req.end();
    });

/** An answer of the shape the exam screen sends, per question type. */
const answerFor = (q) => {
    if (q.question_type === 'multiple_choice') {
        return { question_id: q.id, response: { selected: q.options?.length ? [q.options[0].id] : [] } };
    }
    if (q.question_type === 'gap_filling') {
        return { question_id: q.id, response: { gaps: ['load'] } };
    }
    return { question_id: q.id, response: { text: 'load test' } };
};

const steps = new Map();
const record = (name, res) => {
    if (!steps.has(name)) steps.set(name, { ms: [], status: new Map() });
    const s = steps.get(name);
    s.ms.push(res.ms);
    s.status.set(res.status, (s.status.get(res.status) ?? 0) + 1);
    return res;
};

async function sitTheExam(student) {
    const token = student.token;

    record('availability', await request('GET', '/api/student/availability', token));

    if (PASSWORD && fixtures.quiz_id) {
        record('unlock', await request('POST', `/api/student/quizzes/${fixtures.quiz_id}/unlock`, token, { password: PASSWORD }));
    }

    if (FLOW === 'read') return;

    const start = record('start', await request('POST', `/api/student/tests/${TEST_ID}/start`, token));
    const attemptId = start.json?.attempt?.id;
    if (!attemptId) return;

    const paper = record('open', await request('GET', `/api/student/attempts/${attemptId}`, token));
    const questions = paper.json?.questions ?? [];

    record(
        'submit',
        await request('POST', `/api/student/attempts/${attemptId}/submit`, token, { answers: questions.map(answerFor) })
    );
}

const quantile = (sorted, q) => (sorted.length ? sorted[Math.min(sorted.length - 1, Math.floor(sorted.length * q))] : 0);
const round = (n) => Math.round(n * 10) / 10;

console.log(`${VUS} competitors · ${FLOW} · test #${TEST_ID} · ${BASE}`);
const wall = performance.now();

// They all leave the gate together — that is the thing being measured.
await Promise.all(students.map((s) => sitTheExam(s)));

const seconds = (performance.now() - wall) / 1000;
const report = { base: BASE, flow: FLOW, vus: VUS, test_id: TEST_ID, wall_seconds: round(seconds), steps: {} };

for (const [name, s] of steps) {
    const sorted = [...s.ms].sort((a, b) => a - b);
    report.steps[name] = {
        calls: sorted.length,
        p50: round(quantile(sorted, 0.5)),
        p95: round(quantile(sorted, 0.95)),
        p99: round(quantile(sorted, 0.99)),
        max: round(sorted.at(-1) ?? 0),
        status: Object.fromEntries(s.status),
    };
}

const calls = Object.values(report.steps).reduce((n, s) => n + s.calls, 0);
report.requests = calls;
report.rps = round(calls / seconds);

console.table(report.steps);
console.log(`${calls} requests in ${report.wall_seconds}s — ${report.rps} req/s`);

if (OUT) {
    mkdirSync(OUT.replace(/\/[^/]+$/, ''), { recursive: true });
    writeFileSync(OUT, JSON.stringify(report, null, 2));
    console.log(`report → ${OUT}`);
}
