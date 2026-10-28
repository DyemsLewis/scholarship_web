import { profileValueLabel } from './applicantProfileLabels.js';
import { hasLocationPin } from './locationPins.js';
import { findPhilippineRegion } from './philippineLocations.js';

const hasValue = (value) => value !== null && value !== undefined && String(value).trim() !== '';

export function profileBirthdateLabel(value) {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(String(value ?? ''))) return '';
    const date = new Date(`${value}T00:00:00Z`);
    if (!Number.isFinite(date.getTime()) || date.toISOString().slice(0, 10) !== value) return '';
    return new Intl.DateTimeFormat('en-PH', { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' }).format(date);
}

export function applicantProfileDetails(user = {}, { labels = {}, requiresGuardian, omitUnavailable = false } = {}) {
    const saved = user ?? {};
    const label = (key, value) => {
        if (!hasValue(value)) return '';
        const result = labels[key]?.(value);
        return hasValue(result) && result !== value ? result : profileValueLabel(key, value);
    };
    const item = (key, title, value, extra = {}) => ({ key, label: title, value: value ?? '', ...extra });
    const level = saved.education_level;
    const pathRequired = ['senior_high_school', 'college', 'tvet'].includes(level);
    const pathLabel = {
        senior_high_school: 'Track / strand', college: 'Course / program', tvet: 'Training program',
        als: 'Learning path', junior_high_school: 'Curriculum / program',
    }[level] ?? 'Program / learning path';
    const yearLabel = {
        preschool: 'Grade / level', elementary: 'Grade level', junior_high_school: 'Grade level',
        senior_high_school: 'Grade level', college: 'Year level', tvet: 'Training level', als: 'Learning level',
    }[level] ?? 'Grade / year / level';
    const numericGrade = hasValue(saved.gwa) && Number.isFinite(Number(saved.gwa));
    const recordBased = ['pass_fail', 'other'].includes(saved.grading_scale);
    const academicResult = recordBased ? 'School record'
        : numericGrade ? `${Number(saved.gwa).toFixed(2)}${saved.grading_scale === 'percentage' ? '%' : ''}` : '';
    const academicScale = label('gradingScale', saved.grading_scale);
    const guardianRequired = typeof requiresGuardian === 'boolean' ? requiresGuardian
        : saved.is_minor === true || (hasValue(saved.age) && Number(saved.age) < 18)
            || ['preschool', 'elementary', 'junior_high_school', 'senior_high_school'].includes(level)
            || ['parent_guardian', 'relative', 'school_representative', 'other'].includes(saved.account_managed_by);
    const guardianProvided = ['guardian_name', 'guardian_relationship', 'guardian_contact', 'guardian_email'].some((key) => hasValue(saved[key]))
        || saved.guardian_is_account_owner === true;
    const generalAddress = [saved.city, saved.province, saved.region].filter(hasValue).join(', ');
    const outsideDetailsRelevant = saved.current_scholarship_status !== 'none'
        && (['receiving', 'pending'].includes(saved.current_scholarship_status) || hasValue(saved.current_scholarship_details));

    const sections = [
        {
            id: 'personal', title: 'Personal and contact', icon: 'fa-regular fa-address-card',
            description: 'Identity and current contact information.',
            groups: [{ id: 'personal', items: [
                item('birthdate', 'Date of birth', profileBirthdateLabel(saved.birthdate_iso || saved.birthdate), {
                    detail: hasValue(saved.age) ? `${saved.age} years old` : '',
                }),
                item('gender', 'Gender', label('gender', saved.gender)),
                item('citizenship_status', 'Citizenship', label('citizenship', saved.citizenship_status)),
                item('contact_number', 'Contact number', saved.contact_number),
                item('email', 'Email address', saved.email),
                item('account_managed_by', 'Account managed by', label('accountManager', saved.account_managed_by)),
            ] }],
        },
        {
            id: 'academic', title: 'Learning record', icon: 'fa-solid fa-graduation-cap',
            description: 'Current studies and the latest academic record.',
            groups: [{ id: 'academic', items: [
                item('school', 'School / learning institution', saved.school, { span: 'two', detail: label('schoolType', saved.school_type) }),
                item('enrollment_status', 'Enrollment status', label('enrollmentStatus', saved.enrollment_status)),
                item('education_level', 'Education level', label('educationLevel', level)),
                ...(pathRequired || hasValue(saved.course_or_strand) ? [item('course_or_strand', pathLabel, saved.course_or_strand)] : []),
                item('year_level', yearLabel, saved.year_level),
                ...(level !== 'preschool' || numericGrade || hasValue(saved.grading_scale) ? [
                    item('gwa', recordBased ? 'Academic assessment' : 'Academic result', academicResult, {
                        detail: academicScale || (numericGrade ? 'Grading scale not provided' : ''),
                    }),
                ] : []),
                item('academic_year', 'Academic year', saved.academic_year),
                item('academic_term', 'Record period', label('academicTerm', saved.academic_term)),
                ...(hasValue(saved.learner_reference_number) ? [item('learner_reference_number', 'Student / learner reference', saved.learner_reference_number, { span: 'full' })] : []),
            ] }],
        },
        {
            id: 'household', title: 'Household and guardian', icon: 'fa-solid fa-house-chimney-user',
            description: 'Household circumstances and trusted contact details.',
            groups: [
                { id: 'household', items: [
                    item('income_bracket', 'Household income bracket', saved.income_bracket),
                    item('household_size', 'Household size', hasValue(saved.household_size) ? `${saved.household_size} ${Number(saved.household_size) === 1 ? 'member' : 'members'}` : ''),
                    item('current_scholarship_status', 'Outside scholarship', label('currentScholarship', saved.current_scholarship_status)),
                    ...(outsideDetailsRelevant ? [item('current_scholarship_details', 'Outside scholarship details', saved.current_scholarship_details, { span: 'full' })] : []),
                ] },
                ...(guardianRequired || guardianProvided ? [{
                    id: 'guardian', title: 'Parent or guardian',
                    description: guardianRequired ? 'Contact for consent and scholarship follow-ups.' : 'Optional trusted contact.',
                    items: [
                        item('guardian_name', 'Full name', saved.guardian_name, { span: 'two', detail: saved.guardian_is_account_owner ? 'Manages this account' : '' }),
                        item('guardian_relationship', 'Relationship to learner', label('relationship', saved.guardian_relationship)),
                        item('guardian_contact', 'Contact number', saved.guardian_contact),
                        item('guardian_email', 'Email address', saved.guardian_email, { span: 'two' }),
                    ],
                }] : []),
            ],
            note: guardianRequired || guardianProvided ? '' : 'A guardian contact is optional for this profile.',
        },
        {
            id: 'location', title: 'Home address', icon: 'fa-solid fa-location-dot',
            description: 'General residence used for residency and distance checks.',
            groups: [{ id: 'location', items: [
                item('region', 'Region', labels.region?.(saved.region) || findPhilippineRegion(saved.region)?.label || saved.region),
                item('province', 'Province', saved.province),
                item('city', 'City / municipality', saved.city),
                item('barangay', 'Barangay', saved.barangay),
                item('address', 'Street / home address', saved.address, { span: 'two' }),
            ] }],
            map: {
                address: generalAddress, latitude: saved.latitude ?? null, longitude: saved.longitude ?? null,
                hasPin: hasLocationPin(saved.latitude, saved.longitude),
            },
        },
    ];
    if (!omitUnavailable) return sections;
    // A redacted field is not a missing applicant detail. Keep role-specific exclusions intact.
    return sections.map((section) => ({
        ...section,
        groups: section.groups.map((group) => ({
            ...group,
            items: group.items.filter((field) => Object.hasOwn(saved, field.key) || (field.key === 'birthdate' && Object.hasOwn(saved, 'birthdate_iso'))),
        })).filter((group) => group.items.length),
    })).filter((section) => section.groups.length);
}
