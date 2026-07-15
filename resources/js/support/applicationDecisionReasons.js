export const negativeDecisionStatuses = ['rejected', 'not_awarded', 'exam_failed'];

export const decisionReasonOptions = [
    { value: '', label: 'No reason selected' },
    { value: 'complete_requirements', label: 'Complete requirements' },
    { value: 'missing_documents', label: 'Missing documents' },
    { value: 'academic_requirement_not_met', label: 'Academic requirement not met' },
    { value: 'outside_eligibility', label: 'Outside eligibility' },
    { value: 'for_exam', label: 'Meets exam eligibility' },
    { value: 'exam_scheduled', label: 'Exam scheduled' },
    { value: 'exam_completed', label: 'Exam completed' },
    { value: 'passed_exam', label: 'Passed exam' },
    { value: 'failed_exam', label: 'Failed exam' },
    { value: 'for_interview', label: 'For interview' },
    { value: 'approved_for_award', label: 'Approved for award' },
    { value: 'distribution_scheduled', label: 'Distribution scheduled' },
    { value: 'award_released', label: 'Reward distributed' },
    { value: 'renewed_support', label: 'Renewed support' },
    { value: 'funds_limited', label: 'Funds limited' },
    { value: 'not_selected', label: 'Not selected' },
    { value: 'other', label: 'Other' },
];
