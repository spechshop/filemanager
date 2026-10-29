'use strict';

const fs = require('node:fs');

const SEVERITIES = ['info', 'low', 'moderate', 'high', 'critical'];

function readJson(file, label) {
    let contents;
    try {
        contents = fs.readFileSync(file, 'utf8');
    } catch (error) {
        throw new Error(`${label} could not be read: ${error.message}`);
    }
    try {
        return JSON.parse(contents);
    } catch (error) {
        throw new Error(`${label} is not valid JSON: ${error.message}`);
    }
}

function advisoryId(value) {
    const match = String(value).match(/GHSA-[0-9a-z-]+/i);
    return match ? match[0].toUpperCase() : null;
}

function advisorySet(name, vulnerabilities, visiting = new Set()) {
    if (visiting.has(name)) throw new Error(`npm audit contains a circular via chain at ${name}`);
    const vulnerability = vulnerabilities[name];
    if (!vulnerability) throw new Error(`npm audit refers to missing vulnerability ${name}`);
    const next = new Set(visiting).add(name);
    const advisories = new Set();
    for (const via of vulnerability.via || []) {
        if (typeof via === 'string') {
            for (const id of advisorySet(via, vulnerabilities, next)) advisories.add(id);
            continue;
        }
        if (!via || typeof via !== 'object') throw new Error(`npm audit has an invalid via entry for ${name}`);
        const id = advisoryId(via.url);
        if (!id) throw new Error(`npm audit advisory for ${name} has no GHSA identifier`);
        advisories.add(id);
    }
    if (advisories.size === 0) throw new Error(`npm audit vulnerability ${name} has no resolvable advisory`);
    return [...advisories].sort();
}

function validateBaseline(baseline) {
    if (baseline.schemaVersion !== 1 || baseline.status !== 'accepted-temporarily') {
        throw new Error('accepted-risk baseline has an unsupported schema or status');
    }
    if (!/^https:\/\/github\.com\/spechshop\/filemanager\/issues\/\d+$/.test(baseline.trackingIssue || '')) {
        throw new Error('accepted-risk baseline must link to its tracking issue');
    }
    if (!Array.isArray(baseline.accepted) || baseline.accepted.length !== 8) {
        throw new Error('accepted-risk baseline must contain exactly eight package entries');
    }
    const accepted = new Map();
    for (const entry of baseline.accepted) {
        if (!entry || typeof entry.package !== 'string' || !SEVERITIES.includes(entry.severity)) {
            throw new Error('accepted-risk baseline has an invalid package or severity');
        }
        if (accepted.has(entry.package)) throw new Error(`accepted-risk baseline duplicates ${entry.package}`);
        if (!Array.isArray(entry.advisories) || entry.advisories.length === 0 ||
            entry.advisories.some(id => !/^GHSA-[0-9A-Z-]+$/i.test(id))) {
            throw new Error(`accepted-risk baseline has invalid advisories for ${entry.package}`);
        }
        const advisories = [...new Set(entry.advisories.map(id => id.toUpperCase()))].sort();
        if (advisories.length !== entry.advisories.length) {
            throw new Error(`accepted-risk baseline duplicates an advisory for ${entry.package}`);
        }
        accepted.set(entry.package, {severity: entry.severity, advisories});
    }
    return accepted;
}

function evaluate(report, baseline, auditExitCode) {
    if (![0, 1].includes(auditExitCode)) {
        throw new Error(`npm audit could not be executed successfully (exit ${auditExitCode})`);
    }
    if (!report || report.error) {
        const detail = report && report.error ? `: ${report.error.code || report.error.summary || 'unknown error'}` : '';
        throw new Error(`npm audit returned an execution error${detail}`);
    }
    if (report.auditReportVersion !== 2 || !report.vulnerabilities || !report.metadata?.vulnerabilities) {
        throw new Error('npm audit returned an unsupported or incomplete report');
    }

    const accepted = validateBaseline(baseline);
    const vulnerabilities = report.vulnerabilities;
    const names = Object.keys(vulnerabilities).sort();
    const counts = Object.fromEntries(SEVERITIES.map(severity => [severity, 0]));
    const unexpected = [];

    for (const name of names) {
        const item = vulnerabilities[name];
        if (!item || item.name !== name || !SEVERITIES.includes(item.severity)) {
            throw new Error(`npm audit has invalid vulnerability metadata for ${name}`);
        }
        counts[item.severity]++;
        const actual = {severity: item.severity, advisories: advisorySet(name, vulnerabilities)};
        const allowed = accepted.get(name);
        // The accepted baseline is a ceiling: fixes may remove advisories or lower
        // severity, but cannot introduce an advisory or increase severity.
        if (!allowed || SEVERITIES.indexOf(actual.severity) > SEVERITIES.indexOf(allowed.severity) ||
            actual.advisories.some(id => !allowed.advisories.includes(id))) {
            unexpected.push({package: name, ...actual});
        }
    }

    const metadata = report.metadata.vulnerabilities;
    const total = SEVERITIES.reduce((sum, severity) => sum + counts[severity], 0);
    if (metadata.total !== total || SEVERITIES.some(severity => metadata[severity] !== counts[severity])) {
        throw new Error('npm audit vulnerability totals do not match the report entries');
    }
    if ((total === 0 && auditExitCode !== 0) || (total > 0 && auditExitCode !== 1)) {
        throw new Error(`npm audit exit ${auditExitCode} is inconsistent with ${total} vulnerabilities`);
    }
    if (unexpected.length > 0) {
        throw new Error(`npm audit found vulnerabilities outside the accepted-risk baseline:\n${JSON.stringify(unexpected, null, 2)}`);
    }
    return {total, counts, trackingIssue: baseline.trackingIssue};
}

function summary(result) {
    if (result.total === 0) {
        return '### npm audit: nenhuma vulnerabilidade pendente\n\nA auditoria real foi executada e não retornou alertas. A baseline temporária pode ser removida.\n';
    }
    return `### ⚠️ npm audit: ${result.total} vulnerabilidades conhecidas e aceitas temporariamente\n\n` +
        `O scanner foi executado. Permanecem **${result.counts.critical} críticas, ${result.counts.high} altas, ` +
        `${result.counts.moderate} moderadas, ${result.counts.low} baixas e ${result.counts.info} informativas**. ` +
        `Esses riscos não foram corrigidos; o tratamento está registrado na [issue de acompanhamento](${result.trackingIssue}).\n`;
}

function main(argv = process.argv.slice(2), env = process.env) {
    if (argv.length !== 2) throw new Error('usage: node npm-audit-policy.cjs <audit.json> <accepted-risks.json>');
    const auditExitCode = Number(env.NPM_AUDIT_EXIT_CODE);
    if (!Number.isInteger(auditExitCode)) throw new Error('NPM_AUDIT_EXIT_CODE must contain the npm audit exit code');
    const result = evaluate(readJson(argv[0], 'npm audit report'), readJson(argv[1], 'accepted-risk baseline'), auditExitCode);
    const message = summary(result);
    process.stdout.write(message);
    if (env.GITHUB_STEP_SUMMARY) fs.appendFileSync(env.GITHUB_STEP_SUMMARY, message);
}

if (require.main === module) {
    try {
        main();
    } catch (error) {
        console.error(`npm audit policy failed: ${error.message}`);
        process.exitCode = 1;
    }
}

module.exports = {advisorySet, evaluate, summary, validateBaseline};
