/**
 * Client-side representation of app/validators/PasswordPolicy.php.
 * The server remains authoritative; this module only keeps first-party UI hints aligned.
 */
export const REGISTRATION_PASSWORD_POLICY = Object.freeze({
  minCharacters: 15,
  maxUtf8Bytes: 1024,
});

function isWellFormedUnicode(value) {
  for (let index = 0; index < value.length; index += 1) {
    const codeUnit = value.charCodeAt(index);
    if (codeUnit >= 0xd800 && codeUnit <= 0xdbff) {
      const next = value.charCodeAt(index + 1);
      if (!(next >= 0xdc00 && next <= 0xdfff)) return false;
      index += 1;
    } else if (codeUnit >= 0xdc00 && codeUnit <= 0xdfff) {
      return false;
    }
  }
  return true;
}

/** @returns {{valid:boolean, characterCount:number, byteLength:number, validUnicode:boolean, withinByteLimit:boolean, hasNoControlCharacters:boolean, errors:string[]}} */
export function inspectRegistrationPassword(value) {
  const password = typeof value === "string" ? value : "";
  const validUnicode = isWellFormedUnicode(password);
  const characterCount = Array.from(password).length;
  const byteLength = new TextEncoder().encode(password).length;
  const withinByteLimit = byteLength <= REGISTRATION_PASSWORD_POLICY.maxUtf8Bytes;
  const hasNoControlCharacters = !/[\u0000-\u001F\u007F]/.test(password);
  const errors = [];

  if (password.length === 0 || characterCount < REGISTRATION_PASSWORD_POLICY.minCharacters) {
    errors.push("رمز عبور باید دست‌کم ۱۵ نویسهٔ Unicode داشته باشد.");
  }
  if (!validUnicode) errors.push("رمز عبور شامل توالی نامعتبر Unicode است.");
  if (!withinByteLimit) errors.push("رمز عبور از سقف ۱۰۲۴ بایت UTF-8 بیشتر است.");
  if (!hasNoControlCharacters) errors.push("رمز عبور نباید نویسه‌های کنترلی ASCII داشته باشد.");

  return {
    valid: errors.length === 0,
    characterCount,
    byteLength,
    validUnicode,
    withinByteLimit,
    hasNoControlCharacters,
    errors,
  };
}
