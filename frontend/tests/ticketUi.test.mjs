import assert from "node:assert/strict";
import test from "node:test";
import {
  PRIORITY_OPTIONS,
  STATUS_OPTIONS,
  formatTicketDate,
  priorityLabel,
  readTicketFilters,
  statusLabel,
  ticketCapabilities,
  ticketError,
  writeTicketFilters,
} from "../src/tickets/ticketUi.js";

const noActions = {
  canEdit: false,
  canEditPriority: false,
  canAssign: false,
  canUnassign: false,
  canStart: false,
  canResolve: false,
  canClose: false,
  canReopen: false,
  canComment: false,
  isReadOnly: true,
};

const capabilityCases = [
  ["admin open unassigned", "admin", "open", false, false, false, ["canEdit", "canEditPriority", "canAssign", "canComment"]],
  ["admin open assigned", "admin", "open", false, false, true, ["canEdit", "canEditPriority", "canAssign", "canUnassign", "canStart", "canComment"]],
  ["admin in progress", "admin", "in_progress", false, false, true, ["canEdit", "canEditPriority", "canAssign", "canResolve", "canComment"]],
  ["admin resolved", "admin", "resolved", false, false, true, ["canEdit", "canEditPriority", "canClose", "canReopen", "canComment"]],
  ["admin closed", "admin", "closed", false, false, true, []],
  ["employee own open", "employee", "open", true, false, false, ["canEdit", "canComment"]],
  ["employee own assigned", "employee", "open", true, false, true, ["canComment"]],
  ["employee own in progress", "employee", "in_progress", true, false, true, ["canComment"]],
  ["employee own resolved", "employee", "resolved", true, false, true, ["canClose", "canReopen", "canComment"]],
  ["employee own closed", "employee", "closed", true, false, true, []],
  ["employee outside scope", "employee", "open", false, false, false, []],
  ["technician assigned open", "technician", "open", false, true, true, ["canStart", "canComment"]],
  ["technician assigned in progress", "technician", "in_progress", false, true, true, ["canResolve", "canComment"]],
  ["technician assigned resolved", "technician", "resolved", false, true, true, ["canComment"]],
  ["technician requester resolved", "technician", "resolved", true, true, true, ["canClose", "canReopen", "canComment"]],
  ["technician requester open unassigned", "technician", "open", true, false, false, ["canEdit", "canComment"]],
  ["technician requester open assigned to self", "technician", "open", true, true, true, ["canStart", "canComment"]],
  ["technician requester open assigned elsewhere", "technician", "open", true, false, true, ["canComment"]],
  ["technician outside scope", "technician", "open", false, false, false, []],
];

for (const [name, role, status, owns, assigned, hasTechnician, actions] of capabilityCases) {
  test("ticket capabilities: " + name, () => {
    const user = { id: 10, role, is_active: true };
    const ticket = {
      id: 1,
      status,
      requester: { id: owns ? 10 : 20, name: "Requester" },
      technician: hasTechnician ? { id: assigned ? 10 : 30, name: "Technician" } : null,
    };
    const expected = { ...noActions, isReadOnly: actions.length === 0 };
    for (const action of actions) expected[action] = true;
    assert.deepEqual(ticketCapabilities(user, ticket), expected);
  });
}

for (const role of ["employee", "technician", "admin", "unknown"]) {
  test("inactive or unknown users get no mutation capability: " + role, () => {
    const user = { id: 1, role, is_active: role === "unknown" };
    const ticket = { id: 1, status: "open", requester: { id: 1 }, technician: { id: 1 } };
    assert.deepEqual(ticketCapabilities(user, ticket), noActions);
  });
}

test("missing user or ticket has no mutation capabilities", () => {
  assert.deepEqual(ticketCapabilities(null, {}), noActions);
  assert.deepEqual(ticketCapabilities({ id: 1, role: "admin", is_active: true }, null), noActions);
});

test("status and priority options match API values and all have readable labels", () => {
  assert.deepEqual(STATUS_OPTIONS.map((option) => option.value), ["open", "in_progress", "resolved", "closed"]);
  assert.deepEqual(PRIORITY_OPTIONS.map((option) => option.value), ["low", "normal", "high", "urgent"]);
  for (const option of STATUS_OPTIONS) assert.equal(statusLabel(option.value), option.label);
  for (const option of PRIORITY_OPTIONS) assert.equal(priorityLabel(option.value), option.label);
  for (const option of [...STATUS_OPTIONS, ...PRIORITY_OPTIONS]) assert.ok(option.label.trim());
  assert.equal(typeof statusLabel("unknown"), "string");
  assert.equal(typeof priorityLabel("unknown"), "string");
});

const defaults = { search: "", status: "", priority: "", page: 1, per_page: 15 };

test("filters default to first page and fifteen results", () => {
  assert.deepEqual(readTicketFilters(new URLSearchParams()), defaults);
});

test("whitespace-only search is removed from URL and API filters", () => {
  const whitespace = " \t\n\u00a0 ";
  assert.deepEqual(readTicketFilters(new URLSearchParams({ search: whitespace })), defaults);
  const query = writeTicketFilters({ ...defaults, search: whitespace });
  assert.equal(query.has("search"), false);
  assert.equal(query.toString(), "");
});

test("search is trimmed before its canonical URL round trip", () => {
  const query = writeTicketFilters({ ...defaults, search: "  Printer network  " });
  assert.equal(query.get("search"), "Printer network");
  assert.equal(readTicketFilters(query).search, "Printer network");
});

test("valid filters survive URL round trip and unknown query fields are removed", () => {
  const filters = { search: "TKT-123 & printer", status: "in_progress", priority: "high", page: 3, per_page: 25 };
  const query = writeTicketFilters({ ...filters, unknown: "secret" });
  assert.ok(query instanceof URLSearchParams);
  assert.equal(query.has("unknown"), false);
  assert.deepEqual(readTicketFilters(query), filters);
  assert.equal(writeTicketFilters(readTicketFilters(query)).toString(), query.toString());
});

for (const [query, expected] of [
  ["status=deleted&priority=critical&page=0&per_page=101", defaults],
  ["page=-2&per_page=0", defaults],
  ["page=1.5&per_page=abc", defaults],
  ["page=NaN&per_page=100", { ...defaults, per_page: 100 }],
  ["page=2&per_page=1", { ...defaults, page: 2, per_page: 1 }],
]) {
  test("invalid URL filters are normalized: " + query, () => {
    assert.deepEqual(readTicketFilters(new URLSearchParams(query)), expected);
  });
}

test("filter changes can reset page while retaining other URL state", () => {
  const current = readTicketFilters(new URLSearchParams("search=printer&status=open&priority=high&page=7&per_page=25"));
  const changed = readTicketFilters(writeTicketFilters({ ...current, status: "resolved", page: 1 }));
  assert.deepEqual(changed, { search: "printer", status: "resolved", priority: "high", page: 1, per_page: 25 });
});

test("UTC dates use Casablanca local time independently from the test machine timezone", () => {
  const actual = formatTicketDate("2026-01-18T23:30:00.000000Z");
  const expected = new Intl.DateTimeFormat("fr-MA", {
    dateStyle: "medium",
    timeStyle: "short",
    timeZone: "Africa/Casablanca",
  }).format(new Date("2026-01-18T23:30:00.000000Z"));
  assert.equal(actual, expected);
});

test("invalid dates have a readable fallback", () => {
  assert.equal(formatTicketDate(null), formatTicketDate("invalid"));
  assert.ok(formatTicketDate(null));
});

function responseError(status, data = {}) {
  return { response: { status, data } };
}

test("validation errors expose string fields and tolerate malformed error entries", () => {
  const error = ticketError(responseError(422, { errors: { title: ["Title invalid."], description: "Description invalid.", invalid: {}, empty: [] } }));
  assert.equal(error.kind, "validation");
  assert.deepEqual(error.fields, { title: "Title invalid.", description: "Description invalid." });
  assert.ok(error.message);
});

for (const [status, data, kind] of [
  [401, {}, "session"],
  [419, {}, "session"],
  [403, { code: "account_disabled" }, "forbidden"],
  [403, {}, "forbidden"],
  [404, {}, "not-found"],
  [409, {}, "conflict"],
  [500, {}, "server"],
  [503, {}, "server"],
]) {
  test("API failure classification: " + status + " " + (data.code || ""), () => {
    const error = ticketError(responseError(status, data));
    assert.equal(error.kind, kind);
    assert.deepEqual(error.fields, {});
    assert.ok(error.message);
  });
}

test("cancelled requests stay silent even when a mutation result could be uncertain", () => {
  const error = ticketError({ code: "ERR_CANCELED" }, { uncertain: true });
  assert.equal(error.kind, "cancelled");
});

test("network mutation failures explain an uncertain outcome without inventing validation errors", () => {
  const ordinary = ticketError(new Error("Network Error"));
  const uncertain = ticketError(new Error("Network Error"), { uncertain: true });
  assert.equal(ordinary.kind, "network");
  assert.equal(uncertain.kind, "network");
  assert.deepEqual(uncertain.fields, {});
  assert.notEqual(uncertain.message, ordinary.message);
  assert.match(uncertain.message, /vérifi|enregistr|doublon|effectu/i);
});
