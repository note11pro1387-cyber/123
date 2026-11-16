import { readFile } from 'node:fs/promises';
import { strict as assert } from 'node:assert';
import path from 'node:path';

const root = path.resolve(new URL('../..', import.meta.url).pathname);
const source = await readFile(path.join(root, 'js/registration-validation.js'), 'utf8');
const moduleUrl = `data:text/javascript;base64,${Buffer.from(source, 'utf8').toString('base64')}`;
const { inspectRegistrationPassword, REGISTRATION_PASSWORD_POLICY } = await import(moduleUrl);
let checks = 0;
function check(value, expected, label) {
  assert.equal(inspectRegistrationPassword(value).valid, expected, label);
  checks += 1;
}
assert.equal(REGISTRATION_PASSWORD_POLICY.minCharacters, 15); checks += 1;
assert.equal(REGISTRATION_PASSWORD_POLICY.maxUtf8Bytes, 1024); checks += 1;
check('a'.repeat(8), false, '8 characters must fail');
check('a'.repeat(14), false, '14 characters must fail');
check('a'.repeat(15), true, '15 ASCII characters must pass');
check('ک'.repeat(15), true, '15 Persian Unicode code points must pass');
check('😀'.repeat(15), true, '15 emoji code points must pass');
check('😀'.repeat(256), true, '256 emoji = 1024 UTF-8 bytes must pass');
check('😀'.repeat(257), false, '257 emoji = 1028 UTF-8 bytes must fail');
check('a'.repeat(1025), false, '1025 ASCII bytes must fail');
check('a'.repeat(14) + ' ', true, 'whitespace is not trimmed and counts as a character');
check('a'.repeat(14) + '\n', false, 'ASCII newline must fail');
check('a'.repeat(14) + '\u0000', false, 'ASCII NUL must fail');
const malformed = 'a'.repeat(14) + '\ud800';
check(malformed, false, 'unpaired high surrogate must fail');
assert.equal(inspectRegistrationPassword(malformed).validUnicode, false); checks += 1;
assert.equal(inspectRegistrationPassword('a'.repeat(15)).byteLength, 15); checks += 1;
console.log(`PASS: ${checks} registration password-policy assertions`);
