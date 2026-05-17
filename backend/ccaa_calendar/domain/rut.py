import hashlib
import re

RUT_PATTERN = re.compile(r"^(\d{1,8})-?([\dkK])$")


def normalize_rut(value: str) -> str:
    cleaned = re.sub(r"[\.\s]", "", value or "").upper()
    match = RUT_PATTERN.match(cleaned)
    if not match:
        return cleaned
    number, verifier = match.groups()
    return f"{int(number)}-{verifier}"


def rut_check_digit(number: str) -> str:
    total = 0
    factor = 2

    for digit in reversed(number):
        total += int(digit) * factor
        factor = 2 if factor == 7 else factor + 1

    remainder = 11 - (total % 11)
    if remainder == 11:
        return "0"
    if remainder == 10:
        return "K"
    return str(remainder)


def is_valid_rut(value: str) -> bool:
    normalized = normalize_rut(value)
    match = RUT_PATTERN.match(normalized)
    if not match:
        return False

    number, verifier = match.groups()
    return rut_check_digit(number) == verifier.upper()


def mask_rut(value: str) -> str:
    normalized = normalize_rut(value)
    match = RUT_PATTERN.match(normalized)
    if not match:
        return "invalid"

    number, verifier = match.groups()
    visible_tail = number[-3:]
    return f"***{visible_tail}-{verifier.upper()}"


def rut_hash(value: str, pepper: str) -> str:
    normalized = normalize_rut(value)
    payload = f"{pepper}:{normalized}".encode()
    return hashlib.sha256(payload).hexdigest()
