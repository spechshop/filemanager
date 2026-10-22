'use strict';

const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {evaluate} = require('./npm-audit-policy.cjs');

const ROOT = path.resolve(__dirname, '../..');
const report = JSON.parse(fs.readFileSync(path.join(ROOT, 'docs/security/npm-lsp/audit-after.json'), 'utf8'));
const baseline = JSON.parse(fs.readFileSync(path.join(ROOT, 'docs/security/npm-audit-accepted-risks.json'), 'utf8'));
const clone = value => structuredClone(value);

test('the documented eight findings match the exact accepted-risk baseline', () => {
    const result = evaluate(report, baseline, 1);
    assert.deepEqual(result.counts, {info: 0, low: 0, moderate: 4, high: 4, critical: 0});
    assert.equal(result.total, 8);
});

test('a new vulnerable package is rejected even when it reuses an accepted advisory', () => {
    const changed = clone(report);
    changed.vulnerabilities['new-package'] = {
        name: 'new-package', severity: 'high', via: ['braces'], effects: [], range: '*', nodes: ['node_modules/new-package']
    };
    changed.metadata.vulnerabilities.high++;
    changed.metadata.vulnerabilities.total++;
    assert.throws(() => evaluate(changed, baseline, 1), /outside the accepted-risk baseline/);
});

test('severity or advisory drift in an accepted package is rejected', () => {
    const severity = clone(report);
    severity.vulnerabilities.braces.severity = 'critical';
    severity.metadata.vulnerabilities.high--;
    severity.metadata.vulnerabilities.critical++;
    assert.throws(() => evaluate(severity, baseline, 1), /outside the accepted-risk baseline/);

    const advisory = clone(report);
    advisory.vulnerabilities.braces.via[0].url = 'https://github.com/advisories/GHSA-0000-0000-0000';
    assert.throws(() => evaluate(advisory, baseline, 1), /outside the accepted-risk baseline/);
});

test('audit execution errors and inconsistent output are rejected', () => {
    assert.throws(() => evaluate(report, baseline, 2), /could not be executed/);
    assert.throws(() => evaluate({error: {code: 'ENETUNREACH'}}, baseline, 1), /execution error/);
    assert.throws(() => evaluate(report, baseline, 0), /inconsistent/);
});

test('removing a known vulnerability is allowed and totals remain verified', () => {
    const clean = {auditReportVersion: 2, vulnerabilities: {}, metadata: {vulnerabilities: {
        info: 0, low: 0, moderate: 0, high: 0, critical: 0, total: 0
    }}};
    assert.equal(evaluate(clean, baseline, 0).total, 0);

    const inconsistent = clone(report);
    inconsistent.metadata.vulnerabilities.total = 7;
    assert.throws(() => evaluate(inconsistent, baseline, 1), /totals do not match/);
});
