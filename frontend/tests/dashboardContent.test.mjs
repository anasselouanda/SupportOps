import assert from "node:assert/strict";
import { after, before, test } from "node:test";
import React from "react";
import { renderToStaticMarkup } from "react-dom/server";
import { MemoryRouter } from "react-router-dom";
import { createServer } from "vite";
import react from "@vitejs/plugin-react";
import { fileURLToPath } from "node:url";

let server;
let DashboardContent;
before(async () => {
  server = await createServer({
    root: fileURLToPath(new URL("..", import.meta.url)),
    configFile: false,
    cacheDir: "node_modules/.vite-dashboard-tests",
    appType: "custom",
    plugins: [react()],
    optimizeDeps: { noDiscovery: true, include: [] },
    server: { middlewareMode: true, hmr: false, watch: null },
    ssr: { external: ["react", "react-dom", "react-router-dom", "lucide-react"] },
  });
  ({ default: DashboardContent } = await server.ssrLoadModule("/src/dashboard/DashboardContent.jsx"));
});
after(async () => {
  await server?.close();
});

const user = { id: 1, name: "Amine", role: "employee", is_active: true, email: "private@example.test" };
const stats = { total: 19, open: 8, in_progress: 6, resolved: 3, closed: 2 };
function ticket(id) {
  return {
    id,
    reference: "TKT-REF-" + id,
    title: "Printer request " + id,
    status: "open",
    priority: "normal",
    category: { id: 1, name: "Hardware" },
    technician: id % 2 ? { id: 2, name: "Assigned Technician" } : null,
    created_at: "2026-01-18T23:30:00.000000Z",
  };
}
function render(props = {}) {
  return renderToStaticMarkup(React.createElement(MemoryRouter, null,
    React.createElement(DashboardContent, { user, onRefresh() {}, ...props })));
}

test("initial loading announces progress without invented zero metrics or empty-state claims", () => {
  const html = render({ data: null, pending: true });
  assert.match(html, /role="status"/);
  assert.match(html, /aria-busy="true"/);
  assert.match(html, /Chargement/);
  assert.match(html, /<button[^>]*disabled=""/);
  assert.doesNotMatch(html, /dashboard-stat-value/);
  assert.doesNotMatch(html, /Aucun ticket à afficher/);
});

test("success renders exact API counts and links every status to a ticket filter", () => {
  const html = render({ data: { stats, recent_tickets: [ticket(1), ticket(2)] } });
  assert.match(html, /Bienvenue, Amine/);
  for (const count of Object.values(stats)) {
    assert.match(html, new RegExp('class="dashboard-stat-value">' + count + "<"));
  }
  for (const status of ["open", "in_progress", "resolved", "closed"]) {
    assert.ok(html.includes('href="/tickets?status=' + status + '"'));
  }
  for (const href of ["/tickets", "/tickets/new", "/tickets/1", "/tickets/2"]) {
    assert.ok(html.includes('href="' + href + '"'));
  }
  assert.match(html, /Hardware/);
  assert.match(html, /Assigned Technician/);
  assert.match(html, /Non assigné/);
  assert.match(html, /<time dateTime="2026-01-18T23:30:00.000000Z"/);
  assert.doesNotMatch(html, /private@example/);
});

test("recent ticket panel is defensively limited to five and keeps incoming chronological order", () => {
  const html = render({ data: { stats, recent_tickets: Array.from({ length: 6 }, (_, index) => ticket(index + 1)) } });
  assert.equal((html.match(/class="dashboard-recent-ticket"/g) || []).length, 5);
  assert.doesNotMatch(html, /Printer request 6/);
  assert.ok(html.indexOf("Printer request 1") < html.indexOf("Printer request 5"));
});

test("a true empty response displays five real zeros and an explicit creation link", () => {
  const html = render({ data: { stats: { total: 0, open: 0, in_progress: 0, resolved: 0, closed: 0 }, recent_tickets: [] } });
  assert.equal((html.match(/class="dashboard-stat-value">0</g) || []).length, 5);
  assert.match(html, /Aucun ticket à afficher/);
  assert.ok(html.includes('href="/tickets/new"'));
  assert.doesNotMatch(html, /role="alert"/);
});

test("error masks previously supplied data and offers an accessible retry", () => {
  const html = render({ data: { stats, recent_tickets: [ticket(1)] }, error: { kind: "network", message: "Connection unavailable." } });
  assert.match(html, /role="alert"/);
  assert.match(html, /Connection unavailable/);
  assert.match(html, /Réessayer/);
  assert.doesNotMatch(html, /dashboard-stat-value/);
  assert.doesNotMatch(html, /Printer request 1/);
  assert.doesNotMatch(html, /Aucun ticket à afficher/);
});

test("refresh retains same-scope data while announcing pending state and disabling refresh", () => {
  const html = render({ data: { stats, recent_tickets: [ticket(1)] }, pending: true });
  assert.match(html, /Actualisation de vos indicateurs/);
  assert.match(html, /aria-busy="true"/);
  assert.match(html, /class="dashboard-stat-value">19</);
  assert.match(html, /<button[^>]*disabled=""/);
});

for (const [role, phrase] of [
  ["employee", "Vos indicateurs"],
  ["technician", "ceux qui vous sont assignés"],
  ["admin", "tous les tickets de SupportOps"],
]) {
  test("scope description matches role " + role, () => {
    const html = render({ user: { ...user, role }, data: { stats, recent_tickets: [] } });
    assert.ok(html.includes(phrase));
  });
}

test("missing data is an unavailable state and never a successful zero dashboard", () => {
  const html = render({ data: null, pending: false });
  assert.match(html, /role="alert"/);
  assert.match(html, /Réessayer/);
  assert.doesNotMatch(html, /dashboard-stat-value/);
});
