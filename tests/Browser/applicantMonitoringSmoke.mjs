import { spawn } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const baseUrl = process.env.APPLICANT_MONITORING_SMOKE_BASE_URL ?? 'http://127.0.0.1:8000';
const php = process.env.APPLICANT_MONITORING_SMOKE_PHP ?? 'php';
const browser = process.env.APPLICANT_MONITORING_SMOKE_BROWSER;

function run(command, args, options = {}) {
    return new Promise((resolve, reject) => {
        const child = spawn(command, args, {
            cwd: root,
            stdio: options.capture ? ['ignore', 'pipe', 'pipe'] : 'inherit',
            env: { ...process.env, ...options.env },
        });
        let stdout = '';
        let stderr = '';

        if (options.capture) {
            child.stdout.on('data', (chunk) => { stdout += chunk; });
            child.stderr.on('data', (chunk) => { stderr += chunk; });
        }

        child.once('error', reject);
        child.once('exit', (code) => {
            if (code === 0) {
                resolve(stdout.trim());
                return;
            }

            reject(new Error(stderr.trim() || `${command} exited with code ${code}`));
        });
    });
}

async function phpJson(script, args = []) {
    const output = await run(php, [script, ...args], { capture: true });
    return JSON.parse(output);
}

function directoryRoutes(scenario) {
    const base = `/dashboard/monitoring/${scenario.application_id}`;

    return [
        [base, scenario.program_title],
        [`${base}/check-ins`, 'Check-ins'],
        [`${base}/benefits`, 'Benefit releases'],
        [`${base}/history`, 'Support history'],
    ];
}

function focusedRoutes(scenario) {
    const base = `/dashboard/monitoring/${scenario.application_id}`;
    const routes = [];

    if (scenario.check_in) {
        routes.push([`${base}/check-ins/${scenario.check_in.record_key}`, scenario.check_in.title]);
    }
    if (scenario.release) {
        routes.push([`${base}/benefits/${scenario.release.record_key}`, scenario.release.title]);
    }
    if (scenario.outcome) {
        routes.push([`${base}/history/${scenario.outcome.record_key}`, scenario.outcome.title]);
    }

    return routes;
}

async function smokeScenario(scenario, routes, windowSize) {
    const session = await phpJson('tests/Browser/createSmokeSession.php', [scenario.username]);
    const env = {
        PROVIDER_SMOKE_BASE_URL: baseUrl,
        PROVIDER_SMOKE_SESSION_COOKIE_NAME: session.name,
        PROVIDER_SMOKE_SESSION_COOKIE: session.value,
        PROVIDER_SMOKE_WORKSPACES: JSON.stringify(routes),
        PROVIDER_SMOKE_WINDOW_SIZE: windowSize,
    };

    if (browser) env.PROVIDER_SMOKE_BROWSER = browser;

    await run(process.execPath, ['tests/Browser/providerWorkspaceSmoke.mjs'], { env });
}

async function main() {
    const scenarios = await phpJson('tests/Browser/applicantMonitoringSmokeConfig.php');

    await smokeScenario(
        scenarios.active,
        [...directoryRoutes(scenarios.active), ...focusedRoutes(scenarios.active)],
        '1440,1000',
    );
    await smokeScenario(
        scenarios.completed,
        [...directoryRoutes(scenarios.completed), ...focusedRoutes(scenarios.completed)],
        '1440,1000',
    );
    await smokeScenario(scenarios.active, directoryRoutes(scenarios.active), '390,844');
}

main().catch((error) => {
    console.error(`FAIL ${error.message}`);
    process.exitCode = 1;
});
