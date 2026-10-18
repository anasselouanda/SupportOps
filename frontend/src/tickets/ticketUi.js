export const STATUS_OPTIONS = [
  { value: "open", label: "Ouvert" },
  { value: "in_progress", label: "En cours" },
  { value: "resolved", label: "Résolu" },
  { value: "closed", label: "Clos" },
];
export const PRIORITY_OPTIONS = [
  { value: "low", label: "Basse" },
  { value: "normal", label: "Normale" },
  { value: "high", label: "Haute" },
  { value: "urgent", label: "Urgente" },
];
export const statusLabel = (value) => STATUS_OPTIONS.find((option) => option.value === value)?.label ?? "Statut inconnu";
export const priorityLabel = (value) => PRIORITY_OPTIONS.find((option) => option.value === value)?.label ?? "Priorité inconnue";

const dateFormatter = new Intl.DateTimeFormat("fr-MA", {
  timeZone: "Africa/Casablanca", dateStyle: "medium", timeStyle: "short",
});
export function formatTicketDate(value) {
  if (!value) return "—";
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? "—" : dateFormatter.format(date);
}

export function ticketCapabilities(user, ticket) {
  const active = Boolean(user?.is_active && ["employee", "technician", "admin"].includes(user.role));
  const admin = active && user.role === "admin";
  const owner = active && ticket?.requester?.id != null && String(user.id) === String(ticket.requester.id);
  const assigned = active && user.role === "technician" && ticket?.technician?.id != null && String(user.id) === String(ticket.technician.id);
  const visible = Boolean(ticket && (admin || owner || assigned));
  const open = visible && ticket.status === "open";
  const running = visible && ticket.status === "in_progress";
  const resolved = visible && ticket.status === "resolved";
  const closed = !visible || ticket.status === "closed";
  return {
    canEdit: !closed && (admin || (owner && open && !ticket.technician)),
    canEditPriority: !closed && admin,
    canAssign: admin && (open || running),
    canUnassign: admin && open && Boolean(ticket.technician),
    canStart: open && Boolean(ticket.technician) && (admin || assigned),
    canResolve: running && (admin || assigned),
    canClose: resolved && (admin || owner),
    canReopen: resolved && (admin || owner),
    canComment: !closed,
    isReadOnly: closed,
  };
}

function positiveInteger(value, fallback, max = Number.MAX_SAFE_INTEGER) {
  const text = String(value ?? "");
  const number = Number(text);
  return /^\d+$/.test(text) && Number.isSafeInteger(number) && number >= 1 && number <= max ? number : fallback;
}
export function readTicketFilters(params) {
  const input = params instanceof URLSearchParams ? params : new URLSearchParams(params);
  const status = input.get("status") ?? "";
  const priority = input.get("priority") ?? "";
  return {
    search: (input.get("search") ?? "").slice(0, 255),
    status: STATUS_OPTIONS.some((option) => option.value === status) ? status : "",
    priority: PRIORITY_OPTIONS.some((option) => option.value === priority) ? priority : "",
    page: positiveInteger(input.get("page"), 1),
    per_page: positiveInteger(input.get("per_page"), 15, 100),
  };
}
export function writeTicketFilters(filters) {
  const values = readTicketFilters(new URLSearchParams(Object.entries(filters).filter(([, value]) => value !== undefined && value !== null)));
  const params = new URLSearchParams();
  for (const key of ["search", "status", "priority"]) {
    if (values[key]) params.set(key, values[key]);
  }
  if (values.page !== 1) params.set("page", String(values.page));
  if (values.per_page !== 15) params.set("per_page", String(values.per_page));
  return params;
}

export function ticketError(error, { uncertain = false } = {}) {
  const status = error?.response?.status;
  const fields = {};
  for (const [field, messages] of Object.entries(error?.response?.data?.errors ?? {})) {
    const first = Array.isArray(messages) ? messages[0] : messages;
    if (typeof first === "string") fields[field] = first;
  }
  if (error?.code === "ERR_CANCELED" || error?.name === "AbortError") return { kind: "cancelled", message: "", fields };
  const uncertainMessage = uncertain ? " Le résultat de l’envoi est incertain. Actualisez les données pour vérifier avant de renvoyer." : " Vous pouvez réessayer.";
  if (!error?.response) return { kind: "network", message: `Impossible de joindre le serveur.${uncertainMessage}`, fields };
  if (status >= 500) return { kind: "server", message: `Le serveur a rencontré une erreur.${uncertainMessage}`, fields };
  if (status === 401) return { kind: "session", message: "Votre session est absente ou a expiré. Reconnectez-vous.", fields };
  if (status === 419) return { kind: "session", message: "Votre session ou sa protection CSRF a expiré. Reconnectez-vous avant de réessayer.", fields };
  if (status === 403) return { kind: "forbidden", message: error.response.data?.code === "account_disabled" ? "Votre compte a été désactivé. Contactez un administrateur." : "Vous n’avez pas l’autorisation d’effectuer cette action. Votre session reste active.", fields };
  if (status === 404) return { kind: "not-found", message: "Ce ticket est introuvable ou inaccessible avec votre compte.", fields };
  if (status === 422) return { kind: "validation", message: "Vérifiez les champs signalés et les conditions de cette action.", fields };
  if (status === 409) return { kind: "conflict", message: "Le ticket a changé. Actualisez-le avant de poursuivre.", fields };
  return { kind: "error", message: "L’action n’a pas pu aboutir. Vous pouvez réessayer.", fields };
}
