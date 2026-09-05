#!/usr/bin/env node
// .install/scripts/validate-contract.mjs
//
// Static enforcement of the installer contract (ADR 0002 section 9):
// a manifest schema validator, a dependency-graph acyclicity check, a
// forbidden-token scan, and a git-archive content check. Zero new npm
// dependencies: only node:fs, node:path, node:child_process, node:url.
//
// Accumulate-then-report, matching .install/lib/result.sh's own
// accumulate-then-report convention: every violation across every check is
// collected into one array and printed together, rather than failing on the
// first hit.
//
// Run as: node .install/scripts/validate-contract.mjs

import { readFileSync, readdirSync } from 'node:fs';
import { join, dirname, relative } from 'node:path';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const REPO_ROOT = join(__dirname, '..', '..');
const INSTALL_ROOT = join(REPO_ROOT, '.install');

const violations = [];

function violate(context, message) {
    violations.push(`ERROR: ${context}: ${message}`);
}

function relPath(absolutePath) {
    return relative(REPO_ROOT, absolutePath);
}

// ---------------------------------------------------------------------------
// Discover manifests: the base manifest plus every services/*/manifest.json.
// Globbed, not hardcoded, so a future new service is picked up automatically.
// ---------------------------------------------------------------------------

function discoverManifestPaths() {
    const paths = [join(INSTALL_ROOT, 'base', 'manifest.json')];

    const servicesDir = join(INSTALL_ROOT, 'services');
    const serviceDirs = readdirSync(servicesDir, { withFileTypes: true })
        .filter((entry) => entry.isDirectory())
        .map((entry) => entry.name)
        .sort();

    for (const serviceDir of serviceDirs) {
        paths.push(join(servicesDir, serviceDir, 'manifest.json'));
    }

    return paths;
}

const manifestPaths = discoverManifestPaths();

// ---------------------------------------------------------------------------
// Check 1: manifest field/type validation, hand-rolled against the exact
// known shape of manifest.schema.json (not a generic JSON-Schema library).
// ---------------------------------------------------------------------------

const CAPABILITY_TOKEN_PATTERN = /^[a-z0-9-]+(\.[a-z0-9-]+)*\.v[0-9]+$/;
const ID_PATTERN = /^[a-z0-9-]+$/;
const VERSION_PATTERN = /^[0-9]+\.[0-9]+\.[0-9]+$/;
const PACKAGE_NAME_PATTERN = /^[a-z0-9][a-z0-9+.-]*$/;
const SHA256_PATTERN = /^[a-f0-9]{64}$/;
const OWNERSHIP_PATH_PATTERN = /^\/[^*]*$/;
const SUPPORTED_UBUNTU_VALUES = new Set(['24.04', '26.04']);
const WEB_PROFILE_VALUES = new Set(['nginx', 'apache', 'both']);
const PROTOCOL_VALUES = new Set(['tcp', 'udp']);
const DIRECTION_VALUES = new Set(['inbound', 'outbound']);

const REQUIRED_TOP_LEVEL_KEYS = [
    'schema_version',
    'id',
    'version',
    'supported_ubuntu',
    'depends_on',
    'provides',
    'packages',
    'ports',
    'artifacts',
    'ownership',
];

const ALLOWED_TOP_LEVEL_KEYS = new Set([
    ...REQUIRED_TOP_LEVEL_KEYS,
    'web_profile',
    'backs_up',
]);

function isUniqueArray(array) {
    return new Set(array).size === array.length;
}

function isStringArrayMatching(context, value, pattern, requireUnique, requireMinItems) {
    if (!Array.isArray(value)) {
        violate(context, 'must be an array');
        return;
    }
    if (requireMinItems && value.length < 1) {
        violate(context, 'must have at least one item');
    }
    value.forEach((item, index) => {
        if (typeof item !== 'string' || !pattern.test(item)) {
            violate(`${context}[${index}]`, `"${item}" does not match the required pattern`);
        }
    });
    if (requireUnique && !isUniqueArray(value)) {
        violate(context, 'items must be unique');
    }
}

function validatePackagesArray(context, value) {
    if (!Array.isArray(value)) {
        violate(context, 'must be an array');
        return;
    }
    value.forEach((entry, index) => {
        const entryContext = `${context}[${index}]`;
        if (typeof entry !== 'object' || entry === null || Array.isArray(entry)) {
            violate(entryContext, 'must be an object');
            return;
        }
        for (const key of ['name', 'version', 'sha256']) {
            if (!(key in entry)) {
                violate(entryContext, `missing required key "${key}"`);
            }
        }
        for (const key of Object.keys(entry)) {
            if (!['name', 'version', 'sha256'].includes(key)) {
                violate(entryContext, `unexpected key "${key}"`);
            }
        }
        if ('name' in entry && (typeof entry.name !== 'string' || !PACKAGE_NAME_PATTERN.test(entry.name))) {
            violate(`${entryContext}.name`, `"${entry.name}" does not match the required package-name pattern`);
        }
        if ('version' in entry && (typeof entry.version !== 'string' || entry.version.length < 1)) {
            violate(`${entryContext}.version`, 'must be a non-empty string');
        }
        if ('sha256' in entry && (typeof entry.sha256 !== 'string' || !SHA256_PATTERN.test(entry.sha256))) {
            violate(`${entryContext}.sha256`, 'must be a 64-character lowercase hex sha256 digest');
        }
    });
}

function validatePortsArray(context, value) {
    if (!Array.isArray(value)) {
        violate(context, 'must be an array');
        return;
    }
    value.forEach((entry, index) => {
        const entryContext = `${context}[${index}]`;
        if (typeof entry !== 'object' || entry === null || Array.isArray(entry)) {
            violate(entryContext, 'must be an object');
            return;
        }
        for (const key of ['protocol', 'port', 'direction']) {
            if (!(key in entry)) {
                violate(entryContext, `missing required key "${key}"`);
            }
        }
        for (const key of Object.keys(entry)) {
            if (!['protocol', 'port', 'direction'].includes(key)) {
                violate(entryContext, `unexpected key "${key}"`);
            }
        }
        if ('protocol' in entry && !PROTOCOL_VALUES.has(entry.protocol)) {
            violate(`${entryContext}.protocol`, `"${entry.protocol}" must be one of tcp, udp`);
        }
        if ('port' in entry && (!Number.isInteger(entry.port) || entry.port < 1 || entry.port > 65535)) {
            violate(`${entryContext}.port`, `"${entry.port}" must be an integer between 1 and 65535`);
        }
        if ('direction' in entry && !DIRECTION_VALUES.has(entry.direction)) {
            violate(`${entryContext}.direction`, `"${entry.direction}" must be one of inbound, outbound`);
        }
    });
}

// A URI-shaped string: some scheme followed by ":" and non-whitespace, not
// strictly http(s), since node-health/manifest.json's real artifact source
// legitimately uses "repo:agent/cmd/lesta-agent".
const URI_SHAPED_PATTERN = /^[a-zA-Z][a-zA-Z0-9+.-]*:\S+$/;

function validateArtifactsArray(context, value) {
    if (!Array.isArray(value)) {
        violate(context, 'must be an array');
        return;
    }
    value.forEach((entry, index) => {
        const entryContext = `${context}[${index}]`;
        if (typeof entry !== 'object' || entry === null || Array.isArray(entry)) {
            violate(entryContext, 'must be an object');
            return;
        }
        for (const key of ['name', 'version', 'source', 'sha256', 'signature']) {
            if (!(key in entry)) {
                violate(entryContext, `missing required key "${key}"`);
            }
        }
        for (const key of Object.keys(entry)) {
            if (!['name', 'version', 'source', 'sha256', 'signature'].includes(key)) {
                violate(entryContext, `unexpected key "${key}"`);
            }
        }
        if ('name' in entry && typeof entry.name !== 'string') {
            violate(`${entryContext}.name`, 'must be a string');
        }
        if ('version' in entry && typeof entry.version !== 'string') {
            violate(`${entryContext}.version`, 'must be a string');
        }
        if ('source' in entry && (typeof entry.source !== 'string' || !URI_SHAPED_PATTERN.test(entry.source))) {
            violate(`${entryContext}.source`, `"${entry.source}" must be a URI-shaped string`);
        }
        if ('sha256' in entry && (typeof entry.sha256 !== 'string' || !SHA256_PATTERN.test(entry.sha256))) {
            violate(`${entryContext}.sha256`, 'must be a 64-character lowercase hex sha256 digest');
        }
        if ('signature' in entry && (typeof entry.signature !== 'string' || entry.signature.length < 1)) {
            violate(`${entryContext}.signature`, 'must be a non-empty string');
        }
    });
}

function validateOwnership(context, value) {
    if (typeof value !== 'object' || value === null || Array.isArray(value)) {
        violate(context, 'must be an object');
        return;
    }
    const requiredKeys = ['owned_roots', 'read_only_roots', 'refused_roots'];
    for (const key of requiredKeys) {
        if (!(key in value)) {
            violate(context, `missing required key "${key}"`);
        }
    }
    for (const key of Object.keys(value)) {
        if (!requiredKeys.includes(key)) {
            violate(context, `unexpected key "${key}"`);
        }
    }
    for (const key of requiredKeys) {
        if (key in value) {
            isStringArrayMatching(`${context}.${key}`, value[key], OWNERSHIP_PATH_PATTERN, false, false);
        }
    }
}

/**
 * @param {string} manifestPath
 * @returns {object|null} the parsed manifest, or null on a parse error
 * (already recorded as a violation).
 */
function validateManifestSchema(manifestPath) {
    const context = relPath(manifestPath);
    let raw;
    try {
        raw = readFileSync(manifestPath, 'utf8');
    } catch (error) {
        violate(context, `could not be read: ${error.message}`);
        return null;
    }

    let manifest;
    try {
        manifest = JSON.parse(raw);
    } catch (error) {
        violate(context, `is not valid JSON: ${error.message}`);
        return null;
    }

    if (typeof manifest !== 'object' || manifest === null || Array.isArray(manifest)) {
        violate(context, 'must be a JSON object');
        return null;
    }

    for (const key of REQUIRED_TOP_LEVEL_KEYS) {
        if (!(key in manifest)) {
            violate(context, `missing required key "${key}"`);
        }
    }
    for (const key of Object.keys(manifest)) {
        if (!ALLOWED_TOP_LEVEL_KEYS.has(key)) {
            violate(context, `unexpected key "${key}" (additionalProperties: false)`);
        }
    }

    if ('schema_version' in manifest && manifest.schema_version !== '1') {
        violate(`${context}.schema_version`, `must be the exact string "1", got ${JSON.stringify(manifest.schema_version)}`);
    }
    if ('id' in manifest && (typeof manifest.id !== 'string' || !ID_PATTERN.test(manifest.id))) {
        violate(`${context}.id`, `"${manifest.id}" does not match ^[a-z0-9-]+$`);
    }
    if ('version' in manifest && (typeof manifest.version !== 'string' || !VERSION_PATTERN.test(manifest.version))) {
        violate(`${context}.version`, `"${manifest.version}" does not match the semver pattern`);
    }
    if ('web_profile' in manifest && !WEB_PROFILE_VALUES.has(manifest.web_profile)) {
        violate(`${context}.web_profile`, `"${manifest.web_profile}" must be one of nginx, apache, both`);
    }
    if ('supported_ubuntu' in manifest) {
        if (!Array.isArray(manifest.supported_ubuntu) || manifest.supported_ubuntu.length < 1) {
            violate(`${context}.supported_ubuntu`, 'must be a non-empty array');
        } else {
            manifest.supported_ubuntu.forEach((item, index) => {
                if (!SUPPORTED_UBUNTU_VALUES.has(item)) {
                    violate(`${context}.supported_ubuntu[${index}]`, `"${item}" must be one of 24.04, 26.04`);
                }
            });
            if (!isUniqueArray(manifest.supported_ubuntu)) {
                violate(`${context}.supported_ubuntu`, 'items must be unique');
            }
        }
    }
    if ('depends_on' in manifest) {
        isStringArrayMatching(`${context}.depends_on`, manifest.depends_on, CAPABILITY_TOKEN_PATTERN, true, false);
    }
    if ('provides' in manifest) {
        isStringArrayMatching(`${context}.provides`, manifest.provides, CAPABILITY_TOKEN_PATTERN, true, true);
    }
    if ('backs_up' in manifest) {
        isStringArrayMatching(`${context}.backs_up`, manifest.backs_up, CAPABILITY_TOKEN_PATTERN, true, false);
    }
    if ('packages' in manifest) {
        validatePackagesArray(`${context}.packages`, manifest.packages);
    }
    if ('ports' in manifest) {
        validatePortsArray(`${context}.ports`, manifest.ports);
    }
    if ('artifacts' in manifest) {
        validateArtifactsArray(`${context}.artifacts`, manifest.artifacts);
    }
    if ('ownership' in manifest) {
        validateOwnership(`${context}.ownership`, manifest.ownership);
    }

    return manifest;
}

/** @type {Map<string, object>} manifestPath -> parsed manifest (or the raw object even if it had violations) */
const manifestsByPath = new Map();

for (const manifestPath of manifestPaths) {
    const manifest = validateManifestSchema(manifestPath);
    if (manifest !== null) {
        manifestsByPath.set(manifestPath, manifest);
    }
}

// ---------------------------------------------------------------------------
// Check 2: dependency-graph acyclicity, over depends_on only.
//
// ADR 0002 section 6: "`backs_up` ... is not a gate. It is not part of the
// acyclic dependency graph, and a validator must not treat it as one." This
// check reads only depends_on/provides below; backs_up is never consulted.
// ---------------------------------------------------------------------------

/** @type {Map<string, string>} capability token -> providing manifest id */
const providers = new Map();

for (const manifest of manifestsByPath.values()) {
    if (!Array.isArray(manifest.provides)) {
        continue;
    }
    for (const token of manifest.provides) {
        if (providers.has(token) && providers.get(token) !== manifest.id) {
            violate('dependency graph', `duplicate_capability_provider: "${token}" is provided by both "${providers.get(token)}" and "${manifest.id}"`);
        }
        providers.set(token, manifest.id);
    }
}

/** @type {Map<string, string[]>} manifest id -> array of prerequisite manifest ids (edges A -> B, A before B) */
const dependencyEdges = new Map();

for (const manifest of manifestsByPath.values()) {
    dependencyEdges.set(manifest.id, []);
}

for (const manifest of manifestsByPath.values()) {
    if (!Array.isArray(manifest.depends_on)) {
        continue;
    }
    for (const token of manifest.depends_on) {
        const providerId = providers.get(token);
        if (providerId === undefined) {
            violate('dependency graph', `unresolved_dependency: "${manifest.id}" depends_on "${token}", which nothing provides`);
            continue;
        }
        dependencyEdges.get(providerId)?.push(manifest.id);
    }
}

// Three-color DFS cycle detection (white/gray/black).
const WHITE = 0;
const GRAY = 1;
const BLACK = 2;

const colorById = new Map();
for (const id of dependencyEdges.keys()) {
    colorById.set(id, WHITE);
}

const pathStack = [];
let cycleFound = false;

function dfsVisit(id) {
    if (cycleFound) {
        return;
    }
    colorById.set(id, GRAY);
    pathStack.push(id);

    for (const next of dependencyEdges.get(id) ?? []) {
        if (cycleFound) {
            return;
        }
        const nextColor = colorById.get(next);
        if (nextColor === GRAY) {
            const cycleStart = pathStack.indexOf(next);
            const cyclePath = [...pathStack.slice(cycleStart), next];
            violate('dependency graph', `cycle detected: ${cyclePath.join(' -> ')}`);
            cycleFound = true;
            return;
        }
        if (nextColor === WHITE) {
            dfsVisit(next);
        }
    }

    pathStack.pop();
    colorById.set(id, BLACK);
}

for (const id of dependencyEdges.keys()) {
    if (colorById.get(id) === WHITE) {
        dfsVisit(id);
    }
    if (cycleFound) {
        break;
    }
}

// ---------------------------------------------------------------------------
// Check 3: forbidden-token scan across every install.sh and lib/*.sh, plus
// artifacts[] placeholder-sentinel detection.
// ---------------------------------------------------------------------------

function discoverShellFiles() {
    const files = [];

    const servicesDir = join(INSTALL_ROOT, 'services');
    for (const serviceDir of readdirSync(servicesDir, { withFileTypes: true })) {
        if (!serviceDir.isDirectory()) {
            continue;
        }
        const installShPath = join(servicesDir, serviceDir.name, 'install.sh');
        try {
            readFileSync(installShPath);
            files.push(installShPath);
        } catch {
            // no install.sh in this service directory, nothing to scan
        }
    }

    const libDir = join(INSTALL_ROOT, 'lib');
    for (const entry of readdirSync(libDir, { withFileTypes: true })) {
        if (entry.isFile() && entry.name.endsWith('.sh')) {
            files.push(join(libDir, entry.name));
        }
    }

    return files;
}

// curl ... | sh|bash (optionally sudo'd before sh/bash)
const CURL_PIPE_SHELL = /curl\b.*\|\s*(sudo\s+)?(sh|bash)\b/i;
// wget with -O-/-O -  ... | sh|bash
const WGET_PIPE_SHELL = /wget\b.*-O\s*-.*\|\s*(sudo\s+)?(sh|bash)\b/i;
// eval "$(curl ...)" or eval "$(wget ...)"
const EVAL_REMOTE_FETCH = /eval\b.*\$\((curl|wget)\b/i;
// source <(curl ...) or . <(curl ...)
const SOURCE_PROCESS_SUBSTITUTION = /(source|\.)\s+<\(\s*(curl|wget)\b/i;

const FORBIDDEN_PATTERNS = [CURL_PIPE_SHELL, WGET_PIPE_SHELL, EVAL_REMOTE_FETCH, SOURCE_PROCESS_SUBSTITUTION];

for (const shellFile of discoverShellFiles()) {
    const context = relPath(shellFile);
    const lines = readFileSync(shellFile, 'utf8').split('\n');
    lines.forEach((line, index) => {
        for (const pattern of FORBIDDEN_PATTERNS) {
            if (pattern.test(line)) {
                violate(`${context}:${index + 1}`, `forbidden_remote_execution: line matches an unpinned remote-fetch-into-shell pattern: ${line.trim()}`);
            }
        }
    });
}

const PLACEHOLDER_SENTINELS = ['unsigned', 'none', 'todo', 'n/a', 'changeme', 'example.com'];

for (const [manifestPath, manifest] of manifestsByPath) {
    if (!Array.isArray(manifest.artifacts) || manifest.artifacts.length === 0) {
        continue;
    }
    const context = relPath(manifestPath);
    manifest.artifacts.forEach((artifact, index) => {
        for (const field of ['version', 'source', 'sha256', 'signature']) {
            const value = artifact[field];
            if (typeof value !== 'string' || value.length === 0) {
                violate(`${context}.artifacts[${index}].${field}`, 'placeholder_artifact_field: must be non-empty');
                continue;
            }
            const lowered = value.toLowerCase();
            for (const sentinel of PLACEHOLDER_SENTINELS) {
                if (lowered.includes(sentinel)) {
                    violate(`${context}.artifacts[${index}].${field}`, `placeholder_artifact_field: "${value}" contains the placeholder sentinel "${sentinel}"`);
                }
            }
        }
    });
}

// ---------------------------------------------------------------------------
// Check 4: git archive content check, proving .gitattributes' own
// ".install/** -export-ignore" line is honored in practice.
//
// This only proves the .install tree survives `git archive` against HEAD as
// it stands right now, an inherent limitation of asking git to archive the
// current commit, not a bug in this check.
// ---------------------------------------------------------------------------

function checkGitArchiveContents() {
    let archiveListing;
    try {
        archiveListing = execFileSync('sh', ['-c', 'git archive --format=tar HEAD .install | tar -tf -'], {
            cwd: REPO_ROOT,
            encoding: 'utf8',
        });
    } catch (error) {
        violate('git archive', `failed to run: ${error.message}`);
        return;
    }

    if (!archiveListing || archiveListing.trim().length === 0) {
        violate('git archive', 'archive_missing_path: `git archive --format=tar HEAD .install` produced an empty listing');
        return;
    }

    const archivedPaths = new Set(
        archiveListing
            .split('\n')
            .map((line) => line.trim())
            .filter(Boolean)
            .map((line) => line.replace(/\/$/, '')),
    );

    const expectedPaths = [...manifestPaths, join(INSTALL_ROOT, 'INSTALLER-CONTRACT.md')].map((p) => relPath(p));

    for (const expectedPath of expectedPaths) {
        if (!archivedPaths.has(expectedPath)) {
            violate('git archive', `archive_missing_path: "${expectedPath}" is missing from \`git archive --format=tar HEAD .install\``);
        }
    }
}

checkGitArchiveContents();

// ---------------------------------------------------------------------------
// Report
// ---------------------------------------------------------------------------

if (violations.length > 0) {
    for (const violation of violations) {
        console.error(violation);
    }
    console.error(`\n${violations.length} installer contract violation(s) found.`);
    process.exit(1);
}

console.log(`installer contract: ${manifestPaths.length} manifest(s) validated, dependency graph acyclic, no forbidden tokens found, git archive content confirmed. All checks passed.`);
process.exit(0);
