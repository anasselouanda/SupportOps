import { act } from "react";
import { createRoot } from "react-dom/client";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import { AuthContext } from "../src/auth/AuthContext";
import http from "../src/api/http";
import ProtectedRoute from "../src/routes/ProtectedRoute";
import AdminRoute from "../src/routes/AdminRoute";
import TicketsPage from "../src/pages/TicketsPage";
import AdminUsersPage from "../src/pages/AdminUsersPage";
import NewTicketPage from "../src/pages/NewTicketPage";
import "../src/index.css";
import "../src/App.css";
import "../src/auth.css";

window.IS_REACT_ACT_ENVIRONMENT = true;

const sandbox = document.getElementById("runtime-sandbox");
const resultsElement = document.getElementById("runtime-results");
const summaryElement = document.getElementById("runtime-summary");
const runButton = document.getElementById("run-regressions");
const employee = { id: 101, name: "Employé fictif A", email: "a@example.invalid", role: "employee", is_active: true };
const adminA = { id: 11, name: "Admin fictif A", email: "admin-a@example.invalid", role: "admin", is_active: true };
const accountB = { id: 12, name: "Employé fictif B", email: "employee-b@example.invalid", role: "employee", is_active: true };
let root = null;

function check(condition, message) {
  if (!condition) throw new Error(message);
}

function deferred() {
  let resolve;
  const promise = new Promise((complete) => { resolve = complete; });
  return { promise, resolve };
}

function response(config, data, status = 200) {
  return { config, data, status, statusText: "Fixture locale", headers: {} };
}

function paginatedTickets(title) {
  return {
    data: [{
      id: 501, reference: "TKT-FICTIF-501", title, description: "Donnée de test locale.",
      status: "open", priority: "normal", category: { id: 1, name: "Catégorie fictive" },
      requester: { id: 101, name: "Demandeur fictif" }, technician: null,
      created_at: "2026-10-18T10:00:00Z",
    }],
    links: {},
    meta: { current_page: 1, last_page: 1, total: 1, per_page: 15 },
  };
}

function context(user, refreshUser = async () => {}) {
  return { user, loading: false, sessionError: null, sessionNotice: null, refreshUser, logout: async () => {} };
}

export default function Scenario({ auth, route }) {
  return (
    <AuthContext.Provider value={auth}>
      <MemoryRouter initialEntries={[route]}>
        <Routes>
          <Route element={<ProtectedRoute />}>
            <Route path="/tickets" element={<TicketsPage />} />
            <Route path="/tickets/new" element={<NewTicketPage />} />
            <Route path="/tickets/:id" element={<p>Détail fictif après création explicitement confirmée.</p>} />
          </Route>
          <Route element={<AdminRoute />}>
            <Route path="/admin/users" element={<AdminUsersPage />} />
          </Route>
          <Route path="/login" element={<p>Connexion fictive</p>} />
        </Routes>
      </MemoryRouter>
    </AuthContext.Provider>
  );
}

async function mount(auth, route) {
  root = createRoot(sandbox);
  await act(async () => { root.render(<Scenario auth={auth} route={route} />); });
}

async function changeSession(auth, route) {
  await act(async () => { root.render(<Scenario auth={auth} route={route} />); });
}

async function settle() {
  await act(async () => { await new Promise((resolve) => setTimeout(resolve, 0)); });
}

async function waitFor(predicate, message) {
  for (let attempt = 0; attempt < 100; attempt += 1) {
    if (predicate()) return;
    await settle();
  }
  check(false, message);
}

async function click(element) {
  check(element, "Élément à cliquer introuvable.");
  await act(async () => { element.click(); });
}

function button(text) {
  return [...sandbox.querySelectorAll("button")].find((element) => element.textContent.trim() === text);
}

function input(name) {
  const element = sandbox.querySelector('[name="' + name + '"]');
  check(element, "Champ introuvable : " + name);
  return element;
}

async function setValue(name, value) {
  const element = input(name);
  const prototype = element instanceof HTMLTextAreaElement ? HTMLTextAreaElement.prototype
    : element instanceof HTMLSelectElement ? HTMLSelectElement.prototype : HTMLInputElement.prototype;
  Object.getOwnPropertyDescriptor(prototype, "value").set.call(element, value);
  await act(async () => {
    element.dispatchEvent(new Event(element instanceof HTMLSelectElement ? "change" : "input", { bubbles: true }));
  });
}

async function submit(form, count = 1) {
  check(form, "Formulaire introuvable.");
  await act(async () => {
    for (let index = 0; index < count; index += 1) {
      form.dispatchEvent(new Event("submit", { bubbles: true, cancelable: true }));
    }
  });
}

async function unmount() {
  if (root) {
    await act(async () => { root.unmount(); });
    root = null;
  }
  sandbox.replaceChildren();
}

async function sessionIsolation() {
  const oldRequest = deferred();
  const roleRequest = deferred();
  const signals = [];
  let reads = 0;
  http.defaults.adapter = async (config) => {
    check(config.method === "get" && config.url === "/api/v1/tickets", "Requête inattendue dans le test de session.");
    signals.push(config.signal);
    reads += 1;
    if (reads === 1) return response(config, await oldRequest.promise);
    if (reads === 2) return response(config, paginatedTickets("SESSION_B_EMPLOYEE"));
    if (reads === 3) return response(config, await roleRequest.promise);
    throw new Error("Lecture supplémentaire inattendue.");
  };

  await mount(context(employee), "/tickets");
  await waitFor(() => reads === 1, "La première lecture n’a pas démarré.");
  const nextUser = { ...employee, id: 202, name: "Employé fictif B" };
  await changeSession(context(nextUser), "/tickets");
  await waitFor(() => sandbox.textContent.includes("SESSION_B_EMPLOYEE"), "Les données du second utilisateur manquent.");
  check(signals[0]?.aborted, "Le changement de compte doit annuler l’ancienne lecture.");

  const forbiddenSnapshots = [];
  const observer = new MutationObserver(() => {
    if (sandbox.textContent.includes("ANCIENNE_SESSION_A")) forbiddenSnapshots.push(sandbox.textContent);
  });
  observer.observe(sandbox, { childList: true, subtree: true, characterData: true });
  try {
    oldRequest.resolve(paginatedTickets("ANCIENNE_SESSION_A"));
    await settle();
    check(!sandbox.textContent.includes("ANCIENNE_SESSION_A"), "Une réponse d’ancienne session a été affichée.");
    check(forbiddenSnapshots.length === 0, "Une réponse obsolète est apparue transitoirement.");

    await changeSession(context({ ...nextUser, role: "technician" }), "/tickets");
    await waitFor(() => reads === 3, "Le changement de rôle doit recharger la même URL.");
    check(!sandbox.textContent.includes("SESSION_B_EMPLOYEE"), "L’ancien périmètre reste affiché pendant le changement de rôle.");
    roleRequest.resolve(paginatedTickets("SESSION_B_TECHNICIAN"));
    await waitFor(() => sandbox.textContent.includes("SESSION_B_TECHNICIAN"), "Le nouveau périmètre du rôle n’apparaît pas.");
  } finally {
    observer.disconnect();
    oldRequest.resolve(paginatedTickets("ANCIENNE_SESSION_A"));
    roleRequest.resolve(paginatedTickets("SESSION_B_TECHNICIAN"));
  }
}

async function administrationSaveIsolation() {
  const patch = deferred();
  let patchCount = 0;
  let refreshCalls = 0;
  let reads = 0;
  http.defaults.adapter = async (config) => {
    if (config.method === "get" && config.url === "/sanctum/csrf-cookie") return response(config, {});
    if (config.method === "get" && config.url === "/api/v1/admin/users") {
      reads += 1;
      return response(config, { data: [adminA, accountB], current_page: 1, last_page: 1, total: 2, per_page: 15 });
    }
    if (config.method === "patch" && config.url === "/api/v1/admin/users/11") {
      patchCount += 1;
      return response(config, { user: await patch.promise });
    }
    throw new Error("Aucune autre requête n’est autorisée dans le test admin.");
  };
  await mount(context(adminA, async () => { refreshCalls += 1; }), "/admin/users");
  await waitFor(() => sandbox.querySelector('[aria-label="Modifier le compte de Admin fictif A"]'), "La liste admin n’a pas été chargée.");
  await click(sandbox.querySelector('[aria-label="Modifier le compte de Admin fictif A"]'));
  await setValue("name", "Admin A brouillon en cours");
  await submit(sandbox.querySelector("form.admin-form"), 2);
  await waitFor(() => patchCount === 1, "La sauvegarde A doit envoyer un seul PATCH.");

  const editB = sandbox.querySelector('[aria-label="Modifier le compte de Employé fictif B"]');
  check(editB?.disabled, "Modifier B doit rester désactivé pendant la sauvegarde A.");
  await click(editB);
  check(input("name").value === "Admin A brouillon en cours", "Le brouillon A a été remplacé pendant sa sauvegarde.");
  check(patchCount === 1, "Le double submit a produit plusieurs PATCH.");

  const replacement = { ...adminA, id: 21, name: "Nouvel administrateur fictif" };
  await changeSession(context(replacement, async () => { refreshCalls += 1; }), "/admin/users");
  await waitFor(() => reads >= 2, "La nouvelle session admin doit remonter sa propre liste.");
  patch.resolve({ ...adminA, name: "RÉPONSE_ANCIEN_ADMIN" });
  await settle();
  check(refreshCalls === 0, "Une réponse de l’ancien composant a modifié le profil de la nouvelle session.");
  check(!sandbox.textContent.includes("Le compte a été modifié"), "Une sauvegarde obsolète a publié une confirmation dans la nouvelle session.");
  check(input("name").value === "", "La nouvelle session a reçu le brouillon de l’ancien compte.");
}

async function uncertainCreation() {
  let postCount = 0;
  http.defaults.adapter = async (config) => {
    if (config.method === "get" && config.url === "/api/v1/categories") {
      return response(config, { data: [{ id: 1, name: "Catégorie fictive locale" }] });
    }
    if (config.method === "get" && config.url === "/sanctum/csrf-cookie") return response(config, {});
    if (config.method === "post" && config.url === "/api/v1/tickets") {
      postCount += 1;
      if (postCount === 1) throw new Error("Réseau fictif interrompu après l’envoi.");
      return response(config, { data: { id: 999 } }, 201);
    }
    throw new Error("Requête inattendue dans le test de résultat incertain.");
  };
  await mount(context(employee), "/tickets/new");
  await waitFor(() => !input("category_id").disabled, "Les catégories fictives n’ont pas chargé.");
  await setValue("title", "Demande fictive initiale");
  await setValue("description", "Une description fictive assez longue.");
  await setValue("category_id", "1");
  const form = sandbox.querySelector("form");
  await submit(form);
  await waitFor(() => postCount === 1 && /incertain/i.test(sandbox.textContent), "L’alerte de résultat incertain manque.");
  await setValue("title", "Demande modifiée après erreur");
  check(/incertain/i.test(sandbox.querySelector('[role="alert"]')?.textContent ?? ""), "Retaper dans le formulaire a effacé l’alerte incertaine.");
  check(button("Créer le ticket")?.disabled, "Créer doit rester bloqué avant la vérification explicite.");
  await submit(form);
  await settle();
  check(postCount === 1, "Un deuxième POST a été envoyé sans reconnaissance du résultat incertain.");

  await click(button("J’ai vérifié la liste des tickets"));
  await settle();
  check(postCount === 1, "Le bouton de vérification ne doit pas envoyer de POST.");
  check(!button("Créer le ticket")?.disabled, "La vérification explicite doit permettre un prochain envoi manuel.");
  await submit(form);
  await waitFor(() => postCount === 2 && sandbox.textContent.includes("Détail fictif"), "Seul un nouvel envoi explicite doit produire le deuxième POST.");
}

const scenarios = [
  ["Session et rôle : annulation et absence de données de l’ancien périmètre", sessionIsolation],
  ["Administration : sauvegarde verrouillée, double submit et réponse après changement de session", administrationSaveIsolation],
  ["Création incertaine : avertissement persistant et vérification avant tout nouvel envoi", uncertainCreation],
];

runButton.onclick = async () => {
  if (runButton.disabled) return;
  runButton.disabled = true;
  resultsElement.replaceChildren();
  summaryElement.textContent = "Exécution des trois régressions ReactDOM…";
  const results = [];
  for (const [name, execute] of scenarios) {
    const row = document.createElement("li");
    row.dataset.status = "pending";
    row.textContent = name + " — en cours";
    resultsElement.append(row);
    try {
      await execute();
      row.dataset.status = "passed";
      row.textContent = name + " — PASSED";
      results.push({ name, status: "passed" });
    } catch (error) {
      row.dataset.status = "failed";
      row.textContent = name + " — FAILED : " + error.message;
      results.push({ name, status: "failed", message: error.message });
    } finally {
      await unmount();
    }
  }
  const passed = results.filter((result) => result.status === "passed").length;
  summaryElement.textContent = passed + "/3 régressions réussies. Aucun appel Laravel ni accès à une base.";
  summaryElement.dataset.status = passed === 3 ? "passed" : "failed";
  window.__supportOpsMvpRegressionResults = results;
  runButton.disabled = false;
};
