import { useCallback, useState } from "react";
import { listCategories, updateTicket } from "../api/tickets";
import TicketField from "./TicketField";
import { useTicketDetailResource } from "./TicketDetailResource";
import { PRIORITY_OPTIONS } from "./ticketUi";

export default function TicketActionsEdit({ ticket, canEditPriority, disabled, error, onMutation, onCancel }) {
  const [original] = useState(() => ({ title: ticket.title, description: ticket.description, category_id: String(ticket.category.id), priority: ticket.priority }));
  const [draft, setDraft] = useState(original);
  const [localErrors, setLocalErrors] = useState({});
  const loadCategories = useCallback((signal) => listCategories(signal), []);
  const categories = useTicketDetailResource(loadCategories);
  const serverFields = error?.target === "edit" ? error.fields : {};
  const fields = { ...serverFields, ...localErrors };
  const currentListed = categories.result?.some((category) => String(category.id) === original.category_id);

  function change(field, value) {
    setDraft((previous) => ({ ...previous, [field]: value }));
    setLocalErrors((previous) => ({ ...previous, [field]: undefined, form: undefined }));
  }

  async function submit(event) {
    event.preventDefault();
    if (disabled) return;
    const payload = {};
    const validation = {};
    for (const field of ["title", "description", "category_id", ...(canEditPriority ? ["priority"] : [])]) {
      const value = field === "title" || field === "description" ? draft[field].trim() : draft[field];
      if (value !== original[field]) payload[field] = field === "category_id" ? Number(value) : value;
    }
    if (payload.title !== undefined && (payload.title.length < 3 || payload.title.length > 255)) validation.title = "Le titre doit contenir entre 3 et 255 caractères.";
    if (payload.description !== undefined && (payload.description.length < 10 || payload.description.length > 10000)) validation.description = "La description doit contenir entre 10 et 10 000 caractères.";
    if (payload.category_id !== undefined && (!Number.isInteger(payload.category_id) || payload.category_id < 1)) validation.category_id = "Choisissez une catégorie active.";
    if (!Object.keys(payload).length) validation.form = "Aucune modification à enregistrer.";
    setLocalErrors(validation);
    if (Object.keys(validation).length) return;
    const result = await onMutation({
      key: "edit", execute: () => updateTicket(ticket.id, payload),
      successMessage: "Les informations du ticket ont été enregistrées.",
    });
    if (result.confirmed) onCancel();
  }

  return (
    <form className="ticket-detail-form" onSubmit={submit} noValidate aria-label="Modifier les informations du ticket">
      <h3>Modifier les informations</h3>
      <TicketField name="title" label="Titre" value={draft.title} onChange={(event) => change("title", event.target.value)} required minLength={3} maxLength={255} disabled={disabled} error={fields.title} />
      <TicketField name="description" label="Description" as="textarea" rows={5} value={draft.description} onChange={(event) => change("description", event.target.value)} required minLength={10} maxLength={10000} disabled={disabled} error={fields.description} />
      <TicketField name="category_id" label="Catégorie" as="select" value={draft.category_id} onChange={(event) => change("category_id", event.target.value)} disabled={disabled || categories.pending || Boolean(categories.error)} error={fields.category_id} hint={categories.pending ? "Chargement des catégories actives…" : "Seules les catégories actives peuvent être sélectionnées."}>
        {!currentListed && <option value={original.category_id} disabled>{ticket.category.name} — catégorie actuelle</option>}
        {(categories.result ?? []).map((category) => <option key={category.id} value={category.id}>{category.name}</option>)}
      </TicketField>
      {categories.error && <div className="ticket-alert" role="alert"><p>{categories.error.message}</p><button className="ticket-button" type="button" onClick={categories.load} disabled={disabled || categories.pending}>Recharger les catégories</button></div>}
      {canEditPriority && <TicketField name="priority" label="Priorité" as="select" value={draft.priority} onChange={(event) => change("priority", event.target.value)} disabled={disabled} error={fields.priority}>{PRIORITY_OPTIONS.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}</TicketField>}
      {localErrors.form && <p className="ticket-field-error" role="alert">{localErrors.form}</p>}
      <div className="ticket-actions"><button className="ticket-button ticket-button-primary" type="submit" disabled={disabled}>Enregistrer les modifications</button><button className="ticket-button" type="button" onClick={onCancel} disabled={disabled}>Annuler</button></div>
    </form>
  );
}
