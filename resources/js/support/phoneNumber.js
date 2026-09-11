const PHONE_NUMBER_MAX_DIGITS = 11;
const PHONE_NUMBER_MAX_CHARACTERS = 20;

export function limitPhoneNumber(value) {
    const output = [];
    let digitCount = 0;

    for (const character of String(value ?? '')) {
        if (/\d/.test(character)) {
            if (digitCount >= PHONE_NUMBER_MAX_DIGITS) {
                continue;
            }

            digitCount += 1;
            output.push(character);
        } else if (character === '+' && output.length === 0) {
            output.push(character);
        } else if (/[\s().-]/.test(character) && output.length > 0) {
            output.push(character);
        }

        if (output.length >= PHONE_NUMBER_MAX_CHARACTERS) {
            break;
        }
    }

    return output.join('');
}
