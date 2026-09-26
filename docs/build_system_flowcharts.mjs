import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const outputDir = path.join(root, "deliverables", "system-process-flowcharts");

const palette = {
  ink: "#071326",
  navy: "#0b1930",
  slate: "#4d6380",
  line: "#cbd6e4",
  soft: "#f5f8fc",
  white: "#ffffff",
  amber: "#f5b700",
  amberSoft: "#fff5cf",
  green: "#087f5b",
  greenSoft: "#dff8ee",
  red: "#b42344",
  redSoft: "#ffe8ed",
  blue: "#255d92",
  blueSoft: "#eaf3fb",
  graySoft: "#eef2f7",
};

const diagrams = [
  {
    slug: "01-overall-platform-flow",
    title: "Overall Platform Flow",
    subtitle: "How applicants, providers, administrators, and the system connect",
    width: 1800,
    height: 1080,
    lanes: [
      { name: "SYSTEM", y: 145, h: 155, fill: "#f4f8fc" },
      { name: "APPLICANT", y: 300, h: 235, fill: "#f8fbff" },
      { name: "PROVIDER", y: 535, h: 235, fill: "#fffdf7" },
      { name: "ADMIN", y: 770, h: 205, fill: "#f7f9fc" },
    ],
    nodes: [
      { id: "enter", x: 215, y: 178, w: 210, h: 84, type: "start", title: "Enter platform", body: "Register or sign in" },
      { id: "role", x: 505, y: 170, w: 170, h: 100, type: "decision", title: "Account role?" },
      { id: "profile", x: 745, y: 345, w: 230, h: 105, title: "Complete profile", body: "Add personal, school, household, and supporting information" },
      { id: "discover", x: 1040, y: 345, w: 220, h: 105, title: "Find scholarships", body: "Browse, save, compare, and review program terms" },
      { id: "apply", x: 1325, y: 345, w: 220, h: 105, title: "Apply", body: "System checks profile rules and required files" },
      { id: "result", x: 1590, y: 345, w: 170, h: 105, type: "outcome", title: "Receive result", body: "Selected, waitlisted, or not selected" },
      { id: "provider", x: 745, y: 582, w: 230, h: 105, title: "Verify provider", body: "Submit organization profile and supporting evidence" },
      { id: "program", x: 1040, y: 582, w: 220, h: 105, title: "Create program", body: "Define support, eligibility, files, stages, and terms" },
      { id: "review", x: 1325, y: 582, w: 220, h: 105, title: "Review applicants", body: "Check records and complete configured stages" },
      { id: "monitor", x: 1590, y: 582, w: 170, h: 105, type: "outcome", title: "Monitor recipients", body: "Release support and review continued requirements" },
      { id: "adminProvider", x: 745, y: 818, w: 230, h: 105, type: "admin", title: "Review provider", body: "Approve, request changes, or reject evidence" },
      { id: "adminProgram", x: 1040, y: 818, w: 220, h: 105, type: "admin", title: "Review program", body: "Confirm disclosure, requirements, and readiness" },
      { id: "oversight", x: 1325, y: 818, w: 220, h: 105, type: "admin", title: "Platform oversight", body: "Reports, releases, finance, accounts, and audit logs" },
    ],
    edges: [
      { from: "enter", to: "role" },
      { from: "role", to: "profile", label: "Applicant", fromSide: "bottom", toSide: "top", via: [[590, 320], [860, 320]] },
      { from: "role", to: "provider", label: "Provider", fromSide: "bottom", toSide: "top", via: [[620, 550], [860, 550]] },
      { from: "role", to: "oversight", label: "Admin", fromSide: "bottom", toSide: "top", via: [[650, 790], [1435, 790]] },
      { from: "profile", to: "discover" },
      { from: "discover", to: "apply" },
      { from: "apply", to: "result" },
      { from: "provider", to: "adminProvider", fromSide: "bottom", toSide: "top" },
      { from: "adminProvider", to: "program", label: "Approved", fromSide: "right", toSide: "bottom", via: [[1005, 870], [1150, 870], [1150, 707]] },
      { from: "program", to: "adminProgram", fromSide: "bottom", toSide: "top" },
      { from: "adminProgram", to: "review", label: "Published", fromSide: "right", toSide: "bottom", via: [[1290, 870], [1435, 870], [1435, 707]] },
      { from: "review", to: "result", fromSide: "top", toSide: "bottom", via: [[1435, 500], [1675, 500]] },
      { from: "result", to: "monitor", label: "Selected", fromSide: "bottom", toSide: "top" },
      { from: "monitor", to: "oversight", label: "Evidence", fromSide: "bottom", toSide: "right", via: [[1675, 870], [1565, 870]] },
    ],
    callout: "Matching supports pre-screening. Providers still review evidence and make selection decisions; administrators oversee trust and compliance.",
  },
  {
    slug: "02-provider-program-lifecycle",
    title: "Provider and Program Lifecycle",
    subtitle: "From provider registration to recipient support",
    width: 1800,
    height: 1020,
    lanes: [
      { name: "ONBOARDING", y: 145, h: 190, fill: "#f7f9fc" },
      { name: "PROGRAM SETUP", y: 335, h: 220, fill: "#fffdf7" },
      { name: "SELECTION", y: 555, h: 190, fill: "#f8fbff" },
      { name: "POST-AWARD", y: 745, h: 175, fill: "#f6fbf9" },
    ],
    nodes: [
      { id: "register", x: 215, y: 192, w: 200, h: 96, type: "start", title: "Register provider", body: "Verify email and representative" },
      { id: "evidence", x: 475, y: 192, w: 215, h: 96, title: "Submit evidence", body: "Organization identity, authority, and contact details" },
      { id: "admin", x: 750, y: 192, w: 205, h: 96, type: "admin", title: "Admin review", body: "Approve or request changes" },
      { id: "approved", x: 1020, y: 190, w: 170, h: 100, type: "decision", title: "Approved?" },
      { id: "team", x: 1290, y: 192, w: 220, h: 96, title: "Invite team", body: "Assign role-limited access after email and password setup" },
      { id: "draft", x: 215, y: 397, w: 200, h: 100, title: "Create draft", body: "Start a new scholarship program" },
      { id: "setup", x: 475, y: 397, w: 270, h: 100, title: "Complete setup", body: "Support, dates, eligibility, files, stages, handoff, and terms" },
      { id: "programReview", x: 815, y: 397, w: 220, h: 100, type: "admin", title: "Program review", body: "Admin checks clarity and readiness" },
      { id: "publish", x: 1105, y: 397, w: 200, h: 100, type: "outcome", title: "Publish", body: "Program becomes available to applicants" },
      { id: "deadline", x: 1375, y: 397, w: 230, h: 100, title: "Close new entries", body: "Submitted applications continue after the deadline" },
      { id: "records", x: 215, y: 602, w: 225, h: 96, title: "Review records", body: "Profile, eligibility, documents, and notes" },
      { id: "stages", x: 510, y: 602, w: 240, h: 96, title: "Run selection stages", body: "Formal handoff and optional exam or interview" },
      { id: "decision", x: 820, y: 600, w: 185, h: 100, type: "decision", title: "Final decision" },
      { id: "selected", x: 1085, y: 602, w: 210, h: 96, type: "outcome", title: "Selected", body: "Offer recipient agreement" },
      { id: "waitlist", x: 1365, y: 602, w: 240, h: 96, title: "Waitlist or decline", body: "Promote when a slot opens or close the record" },
      { id: "agreement", x: 215, y: 785, w: 230, h: 92, title: "Agreement accepted", body: "Record disclosed support and responsibilities" },
      { id: "release", x: 520, y: 785, w: 230, h: 92, title: "Schedule release", body: "Prepare support and verify originals" },
      { id: "monitoring", x: 825, y: 785, w: 230, h: 92, title: "Monitor recipient", body: "Review grades, evidence, and circumstances" },
      { id: "close", x: 1130, y: 785, w: 230, h: 92, type: "outcome", title: "Complete or renew", body: "Close support, renew, or end it with a recorded reason" },
    ],
    edges: [
      { from: "register", to: "evidence" }, { from: "evidence", to: "admin" }, { from: "admin", to: "approved" },
      { from: "approved", to: "team", label: "Yes" },
      { from: "approved", to: "evidence", label: "Changes", fromSide: "top", toSide: "top", via: [[1105, 165], [580, 165]], dashed: true },
      { from: "approved", to: "draft", label: "Approved", fromSide: "bottom", toSide: "top", via: [[1105, 355], [315, 355]] },
      { from: "draft", to: "setup" }, { from: "setup", to: "programReview" }, { from: "programReview", to: "publish" }, { from: "publish", to: "deadline" },
      { from: "programReview", to: "setup", label: "Revise", fromSide: "top", toSide: "top", via: [[925, 365], [610, 365]], dashed: true },
      { from: "publish", to: "records", fromSide: "bottom", toSide: "top", via: [[1205, 575], [327, 575]] },
      { from: "records", to: "stages" }, { from: "stages", to: "decision" }, { from: "decision", to: "selected", label: "Select" }, { from: "decision", to: "waitlist", label: "Waitlist / No", via: [[1030, 650], [1365, 650]] },
      { from: "selected", to: "agreement", fromSide: "bottom", toSide: "top", via: [[1190, 762], [330, 762]] },
      { from: "agreement", to: "release" }, { from: "release", to: "monitoring" }, { from: "monitoring", to: "close" },
    ],
    callout: "Team members see only the work allowed by their assigned provider role; the owner retains higher-level control.",
  },
  {
    slug: "03-applicant-application-selection",
    title: "Applicant Application and Selection",
    subtitle: "From profile preparation to the provider's final decision",
    width: 1800,
    height: 1040,
    lanes: [
      { name: "PREPARE", y: 145, h: 175, fill: "#f7f9fc" },
      { name: "APPLY", y: 320, h: 205, fill: "#f8fbff" },
      { name: "PROVIDER REVIEW", y: 525, h: 220, fill: "#fffdf7" },
      { name: "OUTCOME", y: 745, h: 185, fill: "#f6fbf9" },
    ],
    nodes: [
      { id: "account", x: 215, y: 183, w: 210, h: 94, type: "start", title: "Create account", body: "Verify email and sign in" },
      { id: "profile", x: 490, y: 183, w: 225, h: 94, title: "Build profile", body: "Personal, school, household, guardian, and goals" },
      { id: "proof", x: 780, y: 183, w: 235, h: 94, title: "Add evidence", body: "Support profile details and achievements" },
      { id: "browse", x: 1080, y: 183, w: 225, h: 94, title: "Find a program", body: "Review support, deadline, requirements, and terms" },
      { id: "match", x: 215, y: 370, w: 235, h: 100, title: "Check profile fit", body: "Compare saved profile with published structured rules" },
      { id: "blocker", x: 520, y: 368, w: 185, h: 104, type: "decision", title: "Required blocker?" },
      { id: "update", x: 775, y: 370, w: 225, h: 100, title: "Update or choose again", body: "Resolve missing details or review another program" },
      { id: "submit", x: 1080, y: 370, w: 235, h: 100, title: "Submit application", body: "Confirm files, questions, declarations, and terms" },
      { id: "snapshot", x: 1380, y: 370, w: 230, h: 100, title: "Application snapshot", body: "Submitted profile and answers are preserved for review" },
      { id: "prescreen", x: 215, y: 585, w: 225, h: 100, title: "Pre-screening", body: "Provider checks eligibility, records, and evidence" },
      { id: "correction", x: 510, y: 585, w: 225, h: 100, title: "Correction request", body: "Applicant replaces a file or updates requested information" },
      { id: "pass", x: 805, y: 583, w: 180, h: 104, type: "decision", title: "Stage passed?" },
      { id: "handoff", x: 1055, y: 585, w: 235, h: 100, title: "Formal handoff", body: "Follow provider instructions and submit originals when required" },
      { id: "activity", x: 1360, y: 585, w: 250, h: 100, title: "Optional activity", body: "Attend the configured exam or interview, if included" },
      { id: "final", x: 215, y: 785, w: 190, h: 104, type: "decision", title: "Final result" },
      { id: "selected", x: 480, y: 787, w: 210, h: 100, type: "outcome", title: "Selected", body: "Review and accept the recipient agreement" },
      { id: "waitlisted", x: 765, y: 787, w: 210, h: 100, title: "Waitlisted", body: "Provider may promote the applicant if a slot opens" },
      { id: "notSelected", x: 1050, y: 787, w: 210, h: 100, type: "outcome", title: "Not selected", body: "Recorded result remains in application history" },
      { id: "monitor", x: 1335, y: 787, w: 275, h: 100, type: "outcome", title: "Recipient monitoring", body: "Starts only after selection and agreement acceptance" },
    ],
    edges: [
      { from: "account", to: "profile" }, { from: "profile", to: "proof" }, { from: "proof", to: "browse" },
      { from: "browse", to: "match", fromSide: "bottom", toSide: "top", via: [[1192, 340], [332, 340]] },
      { from: "match", to: "blocker" }, { from: "blocker", to: "update", label: "Yes" }, { from: "update", to: "match", label: "Recheck", fromSide: "top", toSide: "top", via: [[887, 345], [332, 345]], dashed: true },
      { from: "blocker", to: "submit", label: "No", via: [[735, 420], [1080, 420]] }, { from: "submit", to: "snapshot" },
      { from: "snapshot", to: "prescreen", fromSide: "bottom", toSide: "top", via: [[1495, 548], [327, 548]] },
      { from: "prescreen", to: "pass" }, { from: "pass", to: "correction", label: "Correct", fromSide: "left", toSide: "right" }, { from: "correction", to: "prescreen", label: "Resubmit", fromSide: "top", toSide: "top", via: [[622, 555], [327, 555]], dashed: true },
      { from: "pass", to: "handoff", label: "Pass" }, { from: "handoff", to: "activity" },
      { from: "activity", to: "final", fromSide: "bottom", toSide: "top", via: [[1485, 765], [310, 765]] },
      { from: "final", to: "selected", label: "Selected" }, { from: "final", to: "waitlisted", label: "Waitlist", via: [[430, 837], [765, 837]] }, { from: "final", to: "notSelected", label: "No", via: [[430, 900], [1155, 900], [1155, 887]] },
      { from: "selected", to: "monitor" },
    ],
    callout: "Online records support initial review. Providers may require original documents before completing a stage or releasing support.",
  },
  {
    slug: "04-recipient-monitoring",
    title: "Benefit Release and Recipient Monitoring",
    subtitle: "How selected applicants receive support and maintain eligibility",
    width: 1800,
    height: 1030,
    lanes: [
      { name: "AGREEMENT", y: 145, h: 175, fill: "#f7f9fc" },
      { name: "BENEFIT RELEASE", y: 320, h: 205, fill: "#fffdf7" },
      { name: "ACADEMIC UPDATE", y: 525, h: 220, fill: "#f8fbff" },
      { name: "SUPPORT OUTCOME", y: 745, h: 180, fill: "#f6fbf9" },
    ],
    nodes: [
      { id: "selected", x: 215, y: 183, w: 210, h: 94, type: "start", title: "Applicant selected", body: "Provider records the final award result" },
      { id: "agreement", x: 490, y: 183, w: 235, h: 94, title: "Review agreement", body: "Support, responsibilities, timeframe, and exceptions" },
      { id: "accept", x: 795, y: 180, w: 185, h: 100, type: "decision", title: "Accepted?" },
      { id: "decline", x: 1050, y: 183, w: 225, h: 94, type: "outcome", title: "Provider follow-up", body: "Decline is recorded with the applicant's note" },
      { id: "schedule", x: 215, y: 370, w: 220, h: 100, title: "Schedule release", body: "Set the date, location, support item, and instructions" },
      { id: "prepare", x: 500, y: 370, w: 220, h: 100, title: "Prepare support", body: "Confirm recipient and original records when required" },
      { id: "release", x: 785, y: 370, w: 220, h: 100, title: "Record release", body: "Released, prepared, missed, or withheld" },
      { id: "receipt", x: 1070, y: 370, w: 230, h: 100, title: "Attach evidence", body: "Keep acknowledgement or release proof for review" },
      { id: "admin", x: 1365, y: 370, w: 230, h: 100, type: "admin", title: "Admin oversight", body: "Review release records, evidence, and reports" },
      { id: "cycle", x: 215, y: 585, w: 235, h: 100, title: "Open monitoring cycle", body: "Set period, due date, grade rule, and instructions" },
      { id: "upload", x: 515, y: 585, w: 225, h: 100, title: "Submit grade record", body: "Applicant uploads a report card or official grade file" },
      { id: "ocr", x: 805, y: 583, w: 190, h: 104, type: "decision", title: "OCR usable?" },
      { id: "result", x: 1060, y: 585, w: 235, h: 100, title: "Confirm result", body: "Use extracted grade or enter the value manually" },
      { id: "providerReview", x: 1360, y: 585, w: 240, h: 100, title: "Provider review", body: "Compare grade, evidence, and circumstances" },
      { id: "met", x: 215, y: 785, w: 220, h: 100, type: "outcome", title: "Requirement met", body: "Prepare the next release or renewal" },
      { id: "correct", x: 500, y: 785, w: 225, h: 100, title: "Needs correction", body: "Applicant replaces the file or clarifies the result" },
      { id: "circumstance", x: 790, y: 785, w: 250, h: 100, title: "Review circumstances", body: "Consider illness, transfer, withdrawal, or family concerns" },
      { id: "end", x: 1105, y: 785, w: 250, h: 100, type: "outcome", title: "Record support outcome", body: "Continue, renew, complete, hold, or end support" },
    ],
    edges: [
      { from: "selected", to: "agreement" }, { from: "agreement", to: "accept" }, { from: "accept", to: "decline", label: "No" },
      { from: "accept", to: "schedule", label: "Yes", fromSide: "bottom", toSide: "top", via: [[887, 340], [325, 340]] },
      { from: "schedule", to: "prepare" }, { from: "prepare", to: "release" }, { from: "release", to: "receipt" }, { from: "receipt", to: "admin" },
      { from: "release", to: "cycle", fromSide: "bottom", toSide: "top", via: [[895, 550], [332, 550]] },
      { from: "cycle", to: "upload" }, { from: "upload", to: "ocr" }, { from: "ocr", to: "result", label: "Yes / manual" }, { from: "result", to: "providerReview" },
      { from: "providerReview", to: "met", label: "Met", fromSide: "bottom", toSide: "top", via: [[1480, 765], [325, 765]] },
      { from: "providerReview", to: "correct", label: "Correction", fromSide: "bottom", toSide: "top", via: [[1480, 755], [612, 755]], dashed: true },
      { from: "correct", to: "upload", label: "Resubmit", fromSide: "top", toSide: "top", via: [[612, 555], [627, 555]], dashed: true },
      { from: "providerReview", to: "circumstance", label: "Concern", fromSide: "bottom", toSide: "top", via: [[1480, 745], [915, 745]] },
      { from: "met", to: "end" }, { from: "circumstance", to: "end" },
    ],
    callout: "A low or missing grade does not automatically terminate support. The provider records a review, considers circumstances, and documents the next action.",
  },
  {
    slug: "05-admin-services-oversight",
    title: "Administration, Reports, Finance, and Services",
    subtitle: "Platform governance and optional provider support",
    width: 1800,
    height: 1000,
    lanes: [
      { name: "ADMIN REVIEW", y: 145, h: 205, fill: "#f7f9fc" },
      { name: "ACCOUNTABILITY", y: 350, h: 205, fill: "#f8fbff" },
      { name: "OPTIONAL SERVICES", y: 555, h: 255, fill: "#fffdf7" },
      { name: "COMPLETION", y: 810, h: 100, fill: "#f6fbf9" },
    ],
    nodes: [
      { id: "admin", x: 215, y: 200, w: 210, h: 96, type: "start", title: "Admin signs in", body: "Assigned role controls available tools" },
      { id: "queue", x: 490, y: 200, w: 230, h: 96, type: "admin", title: "Open review queue", body: "Providers, programs, applicant records, or reported issues" },
      { id: "check", x: 785, y: 200, w: 225, h: 96, type: "admin", title: "Check evidence", body: "Compare submitted details, files, and activity history" },
      { id: "decision", x: 1080, y: 198, w: 185, h: 100, type: "decision", title: "Decision" },
      { id: "return", x: 1335, y: 200, w: 250, h: 96, type: "outcome", title: "Record result", body: "Approve, request changes, reject, or resolve" },
      { id: "reports", x: 215, y: 405, w: 235, h: 96, title: "Handle reports", body: "Track category, description, status, and response" },
      { id: "finance", x: 515, y: 405, w: 225, h: 96, title: "Review finance", body: "Keep service payment and receipt records" },
      { id: "releases", x: 805, y: 405, w: 235, h: 96, title: "Check releases", body: "Review benefit schedules, outcomes, and proof" },
      { id: "audit", x: 1105, y: 405, w: 230, h: 96, title: "Audit activity", body: "Trace important account and workflow actions" },
      { id: "request", x: 215, y: 620, w: 225, h: 100, type: "start", title: "Provider requests help", body: "Choose a one-time support service" },
      { id: "scope", x: 505, y: 620, w: 225, h: 100, title: "Describe need", body: "Specify the problem, preferred schedule, and files" },
      { id: "payment", x: 795, y: 620, w: 210, h: 100, title: "Confirm payment", body: "Required only for a paid service" },
      { id: "assign", x: 1070, y: 620, w: 225, h: 100, type: "admin", title: "Assign request", body: "Admin confirms ownership and meeting schedule" },
      { id: "workspace", x: 1360, y: 620, w: 235, h: 100, title: "Focused workspace", body: "Discuss the request and record the deliverable" },
      { id: "complete", x: 710, y: 825, w: 380, h: 72, type: "outcome", title: "Close with an accountable record", body: "Notifications, receipts, decisions, and history remain available" },
    ],
    edges: [
      { from: "admin", to: "queue" }, { from: "queue", to: "check" }, { from: "check", to: "decision" }, { from: "decision", to: "return" },
      { from: "decision", to: "queue", label: "Changes", fromSide: "top", toSide: "top", via: [[1172, 172], [605, 172]], dashed: true },
      { from: "reports", to: "complete", fromSide: "bottom", toSide: "top", via: [[332, 785], [900, 785]] },
      { from: "finance", to: "complete", fromSide: "bottom", toSide: "top", via: [[627, 775], [900, 775]] },
      { from: "releases", to: "complete", fromSide: "bottom", toSide: "top", via: [[922, 770], [900, 770]] },
      { from: "audit", to: "complete", fromSide: "bottom", toSide: "top", via: [[1220, 780], [900, 780]] },
      { from: "request", to: "scope" }, { from: "scope", to: "payment" }, { from: "payment", to: "assign" }, { from: "assign", to: "workspace" },
      { from: "workspace", to: "complete", fromSide: "bottom", toSide: "right", via: [[1477, 860], [1090, 860]] },
    ],
    callout: "Services are optional one-time support requests. They do not influence applicant matching, provider approval, or scholarship selection.",
  },
];

function esc(value) {
  return String(value ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;");
}

function wrap(text, maxChars) {
  const words = String(text ?? "").split(/\s+/).filter(Boolean);
  const lines = [];
  let line = "";
  for (const word of words) {
    const next = line ? `${line} ${word}` : word;
    if (next.length > maxChars && line) {
      lines.push(line);
      line = word;
    } else {
      line = next;
    }
  }
  if (line) lines.push(line);
  return lines;
}

function textBlock(lines, x, y, options = {}) {
  const { size = 16, weight = 400, color = palette.ink, anchor = "start", lineHeight = 22, family = "Aptos, Segoe UI, sans-serif" } = options;
  return `<text x="${x}" y="${y}" text-anchor="${anchor}" fill="${color}" font-family="${family}" font-size="${size}" font-weight="${weight}">${lines.map((line, index) => `<tspan x="${x}" dy="${index === 0 ? 0 : lineHeight}">${esc(line)}</tspan>`).join("")}</text>`;
}

function nodeStyle(type) {
  if (type === "start") return { fill: palette.navy, stroke: palette.navy, title: palette.white, body: "#d7e2f0" };
  if (type === "outcome") return { fill: palette.greenSoft, stroke: "#72d2b2", title: "#075f46", body: "#216b58" };
  if (type === "admin") return { fill: palette.blueSoft, stroke: "#9bbddb", title: "#174d7c", body: "#416684" };
  return { fill: palette.white, stroke: palette.line, title: palette.ink, body: palette.slate };
}

function renderNode(node) {
  const style = nodeStyle(node.type);
  const cx = node.x + node.w / 2;
  const cy = node.y + node.h / 2;
  let shape;
  if (node.type === "decision") {
    shape = `<polygon points="${cx},${node.y} ${node.x + node.w},${cy} ${cx},${node.y + node.h} ${node.x},${cy}" fill="${palette.amberSoft}" stroke="${palette.amber}" stroke-width="2"/>`;
  } else {
    const rx = node.type === "start" ? 24 : 12;
    shape = `<rect x="${node.x}" y="${node.y}" width="${node.w}" height="${node.h}" rx="${rx}" fill="${style.fill}" stroke="${style.stroke}" stroke-width="2"/>`;
    if (node.type === "outcome") {
      shape += `<rect x="${node.x + 6}" y="${node.y + 6}" width="${node.w - 12}" height="${node.h - 12}" rx="8" fill="none" stroke="#9edfc9"/>`;
    }
  }
  const titleColor = node.type === "decision" ? palette.ink : style.title;
  const bodyColor = node.type === "decision" ? palette.slate : style.body;
  const maxChars = Math.max(16, Math.floor((node.w - 32) / 8.2));
  const titleLines = wrap(node.title, maxChars);
  const bodyLines = wrap(node.body, maxChars + 4).slice(0, 3);
  const titleStart = node.body ? node.y + 32 : cy - ((titleLines.length - 1) * 10);
  const bodyStart = titleStart + titleLines.length * 21 + 7;
  return `<g filter="url(#shadow)">${shape}</g>${textBlock(titleLines, cx, titleStart, { size: 17, weight: 700, color: titleColor, anchor: "middle", lineHeight: 20 })}${bodyLines.length ? textBlock(bodyLines, cx, bodyStart, { size: 13.5, color: bodyColor, anchor: "middle", lineHeight: 18 }) : ""}`;
}

function anchor(node, side) {
  const points = {
    left: [node.x, node.y + node.h / 2],
    right: [node.x + node.w, node.y + node.h / 2],
    top: [node.x + node.w / 2, node.y],
    bottom: [node.x + node.w / 2, node.y + node.h],
  };
  return points[side] ?? points.right;
}

function renderEdge(edge, nodeMap) {
  const from = anchor(nodeMap.get(edge.from), edge.fromSide ?? "right");
  const to = anchor(nodeMap.get(edge.to), edge.toSide ?? "left");
  const points = [from, ...(edge.via ?? []), to];
  const pathData = points.map((point, index) => `${index ? "L" : "M"} ${point[0]} ${point[1]}`).join(" ");
  const middle = points[Math.floor((points.length - 1) / 2)];
  const dash = edge.dashed ? ' stroke-dasharray="8 7"' : "";
  const label = edge.label
    ? `<g><rect x="${middle[0] - 39}" y="${middle[1] - 25}" width="78" height="22" rx="10" fill="#ffffff" stroke="#d7e0eb"/><text x="${middle[0]}" y="${middle[1] - 10}" text-anchor="middle" fill="${palette.slate}" font-family="Aptos, Segoe UI, sans-serif" font-size="12" font-weight="700">${esc(edge.label)}</text></g>`
    : "";
  return `<path d="${pathData}" fill="none" stroke="#8193a9" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" marker-end="url(#arrow)"${dash}/>${label}`;
}

function renderDiagram(diagram) {
  const nodeMap = new Map(diagram.nodes.map((node) => [node.id, node]));
  const lanes = diagram.lanes.map((lane) => `
    <rect x="28" y="${lane.y}" width="${diagram.width - 56}" height="${lane.h}" fill="${lane.fill}" stroke="#d9e2ed"/>
    <rect x="28" y="${lane.y}" width="150" height="${lane.h}" fill="#edf2f8" stroke="#d9e2ed"/>
    <text x="103" y="${lane.y + 34}" text-anchor="middle" fill="#526985" font-family="Aptos, Segoe UI, sans-serif" font-size="12" font-weight="800" letter-spacing="2">${esc(lane.name)}</text>`).join("");
  const edges = diagram.edges.map((edge) => renderEdge(edge, nodeMap)).join("\n");
  const nodes = diagram.nodes.map(renderNode).join("\n");
  const calloutY = diagram.height - 74;
  return `<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" width="${diagram.width}" height="${diagram.height}" viewBox="0 0 ${diagram.width} ${diagram.height}" role="img" aria-labelledby="title description">
  <title id="title">${esc(diagram.title)}</title>
  <desc id="description">${esc(diagram.subtitle)}</desc>
  <defs>
    <filter id="shadow" x="-20%" y="-20%" width="140%" height="140%"><feDropShadow dx="0" dy="3" stdDeviation="4" flood-color="#0b1930" flood-opacity="0.08"/></filter>
    <marker id="arrow" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M 0 0 L 10 5 L 0 10 z" fill="#8193a9"/></marker>
  </defs>
  <rect width="100%" height="100%" fill="#ffffff"/>
  <rect width="100%" height="118" fill="${palette.navy}"/>
  <rect width="18" height="118" fill="${palette.amber}"/>
  ${textBlock([diagram.title], 58, 52, { size: 30, weight: 800, color: palette.white })}
  ${textBlock([diagram.subtitle], 58, 84, { size: 16, color: "#c8d5e6" })}
  <text x="1740" y="69" text-anchor="end" fill="#f6c744" font-family="Aptos, Segoe UI, sans-serif" font-size="12" font-weight="800" letter-spacing="2">SCHOLARSHIP PORTAL</text>
  ${lanes}
  <g>${edges}</g>
  <g>${nodes}</g>
  <rect x="28" y="${calloutY - 20}" width="${diagram.width - 56}" height="58" rx="10" fill="#fff8dd" stroke="#f2d36b"/>
  <circle cx="58" cy="${calloutY + 9}" r="12" fill="${palette.amber}"/>
  <text x="58" y="${calloutY + 14}" text-anchor="middle" fill="${palette.ink}" font-family="Aptos, Segoe UI, sans-serif" font-size="16" font-weight="800">i</text>
  ${textBlock(wrap(diagram.callout, 190), 82, calloutY + 14, { size: 14, color: "#425774", lineHeight: 18 })}
</svg>`;
}

function buildIndex() {
  const cards = diagrams.map((diagram, index) => `
    <article class="diagram" id="chart-${index + 1}">
      <div class="diagram-heading"><div><span>PROCESS FLOW</span><h2>${esc(diagram.title)}</h2><p>${esc(diagram.subtitle)}</p></div><a href="${diagram.slug}.svg" download>Download SVG</a></div>
      <img src="${diagram.slug}.svg" alt="${esc(diagram.title)} flowchart">
    </article>`).join("\n");
  return `<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Scholarship Platform Process Flowcharts</title>
<style>
:root{--ink:#071326;--navy:#0b1930;--amber:#f5b700;--line:#d8e1ec;--muted:#566c87;--paper:#f2f5f9}*{box-sizing:border-box}body{margin:0;background:var(--paper);color:var(--ink);font-family:Aptos,"Segoe UI",sans-serif}.cover{padding:54px max(28px,calc((100vw - 1480px)/2));background:var(--navy);color:white;border-top:8px solid var(--amber)}.cover span,.diagram-heading span{color:#d99100;font-size:12px;font-weight:800;letter-spacing:.22em}.cover h1{max-width:840px;margin:12px 0 10px;font-family:Georgia,serif;font-size:42px}.cover p{max-width:800px;margin:0;color:#c9d6e6;font-size:17px;line-height:1.6}.contents{display:flex;flex-wrap:wrap;gap:8px;margin-top:28px}.contents a{padding:9px 12px;border:1px solid #40516c;border-radius:7px;color:white;text-decoration:none;font-size:13px}main{max-width:1540px;margin:28px auto;padding:0 24px 60px}.diagram{margin-bottom:28px;background:white;border:1px solid var(--line);border-radius:12px;box-shadow:0 8px 28px rgba(7,19,38,.06);overflow:hidden}.diagram-heading{display:flex;align-items:center;justify-content:space-between;gap:24px;padding:24px 28px;border-bottom:1px solid var(--line)}.diagram-heading h2{margin:7px 0 4px;font-size:24px}.diagram-heading p{margin:0;color:var(--muted)}.diagram-heading a{white-space:nowrap;padding:10px 14px;border:1px solid #b7c5d6;border-radius:7px;color:var(--ink);font-weight:700;text-decoration:none}.diagram img{display:block;width:100%;height:auto}.notes{padding:22px 28px;background:#fff9e3;border:1px solid #ecd57b;border-radius:12px}.notes h2{margin-top:0}.notes li{margin:10px 0;color:#405572;line-height:1.55}@media(max-width:700px){.cover h1{font-size:32px}.diagram-heading{align-items:flex-start;flex-direction:column}.diagram{overflow-x:auto}.diagram img{min-width:1050px}}
@media print{body{background:white}.cover{break-after:page}.diagram{break-after:page;box-shadow:none;margin:0;border:0}.diagram-heading a,.contents{display:none}main{max-width:none;margin:0;padding:0}.notes{break-before:page}}
</style></head><body>
<header class="cover"><span>IMPLEMENTED SYSTEM REFERENCE</span><h1>Scholarship Platform Process Flowcharts</h1><p>A visual guide to provider onboarding, scholarship publishing, applicant selection, post-award monitoring, and administrative oversight.</p><nav class="contents">${diagrams.map((diagram, index) => `<a href="#chart-${index + 1}">${index + 1}. ${esc(diagram.title)}</a>`).join("")}</nav></header>
<main>${cards}
<section class="notes"><h2>How to read these charts</h2><ul><li>Matching is decision support, not the provider's final selection decision.</li><li>Exam and interview stages appear only when configured for a program.</li><li>Online documents support initial checking; providers may require originals for complete verification.</li><li>The application deadline closes new applications, while already submitted records continue through review.</li><li>Monitoring begins after selection and acceptance of the recipient agreement.</li></ul></section></main></body></html>`;
}

fs.mkdirSync(outputDir, { recursive: true });
for (const diagram of diagrams) {
  fs.writeFileSync(path.join(outputDir, `${diagram.slug}.svg`), renderDiagram(diagram), "utf8");
}
fs.writeFileSync(path.join(outputDir, "index.html"), buildIndex(), "utf8");
console.log(`Created ${diagrams.length} SVG flowcharts in ${outputDir}`);
