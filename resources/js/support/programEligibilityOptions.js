import { juniorHighPathOptions, seniorHighPathOptions, collegePathOptions, tvetPathOptions, alsPathOptions } from './learnerProgramPaths.js';
import { isOpenEligibilityRule } from './providerEligibilityRules.js';

const courseCatalogs = { junior_high_school: juniorHighPathOptions, senior_high_school: seniorHighPathOptions, college: collegePathOptions, tvet: tvetPathOptions, als: alsPathOptions };
const yearCatalogs = {
    preschool: ['Nursery', 'Kinder 1', 'Kinder 2'],
    elementary: Array.from({ length: 6 }, (_, i) => `Grade ${i + 1}`),
    junior_high_school: Array.from({ length: 4 }, (_, i) => `Grade ${i + 7}`),
    senior_high_school: ['Grade 11', 'Grade 12'],
    college: ['1st year', '2nd year', '3rd year', '4th year', '5th year', 'Graduating'],
    tvet: ['NC I', 'NC II', 'NC III', 'NC IV', 'First term', 'Second term'],
    als: ['Basic literacy', 'Elementary level', 'Junior high school level'],
};

export function eligibilityListValues(value) {
    return [...new Map(String(value ?? '').split(/[\r\n,]+/).map(item => item.trim()).filter(Boolean).map(item => [item.toLowerCase(), item])).values()];
}

export function addEligibilityOption(current, value) {
    const choice = String(value ?? '').trim();
    if (!choice) return current;
    if (isOpenEligibilityRule(choice)) return choice;
    const values = eligibilityListValues(current).filter(item => !isOpenEligibilityRule(item));
    if (!values.some(item => item.toLowerCase() === choice.toLowerCase())) values.push(choice);
    return values.join('\n');
}

export function programEligibilityOptions(levels = []) {
    const selected = levels.length ? levels : Object.keys(yearCatalogs);
    return {
        courses: [...new Set(selected.flatMap(level => courseCatalogs[level] ?? []))].filter(value => value !== 'Other' && !isOpenEligibilityRule(value)),
        years: [...new Set(selected.flatMap(level => yearCatalogs[level] ?? []))],
    };
}
