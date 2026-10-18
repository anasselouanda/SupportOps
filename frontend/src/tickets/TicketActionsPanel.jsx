import { useCallback, useEffect, useRef, useState } from "react";
import { assignTicket, changeTicketStatus, listActiveTechnicians } from "../api/tickets";
import TicketActionsEdit from "./TicketActionsEdit";
import TicketField from "./TicketField";
import { useTicketDetailResource } from "./TicketDetailResource";

export default function TicketActionsPanel({ ticket, capabilities, disabled, error, onMutation }) {
  const [editing, setEditing] = useState(false);
  const [technicianId, setTechnicianId] = useState(() => ticket.technician ? String(ticket.technician.id) : "");
  const [resolutionNote, setResolutionNote] = useState("");
  const [resolutionError, setResolutionError] = useState(null);
  const [confirmation, setConfirmation] = useState(null);
  const confirmationRef = useRef(null);
  const triggerRef = useRef(null);
  const loadTechnicians = useCallback(async (signal) => {
    if (!capabilities.canAssign) return [];
    const collected = new Map();
    let lastPage = 1;
    for (let page = 1; page <= lastPage; page += 1) {
      const response = await listActiveTechnicians({ page, per_page: 100 }, signal);
      if (signal.aborted) throw new DOMException("Aborted", "AbortError");
      lastPage = Number(response.meta.last_page);
      for (const technician of response.data) collected.set(String(technician.id), technician);
    }
    return [...collected.values()].sort((first, second) => first.name.localeCompare(second.name, "fr"));
  }, [capabilities.canAssign]);
  const technicians = useTicketDetailResource(loadTechnicians);
  const currentTechnicianListed = technicians.result?.some((technician) => String(technician.id) === String(ticket.technician?.id));

  useEffect(() => {
    if (confirmation) confirmationRef.current?.focus();
  }, [confirmation]);

  function prepareConfirmation(nextConfirmation) {
    if (disabled) return;
    triggerRef.current = document.activeElement;
    setConfirmation(nextConfirmation);
  }

  function cancelConfirmation() {
    setConfirmation(null);
    triggerRef.current?.focus();
  }

  function confirmStatus(key, status, title, message, successMessage, extra = {}) {
    prepareConfirmation({ key, title, message, successMessage, execute: () => changeTicketStatus(ticket.id, { status, ...extra }) });
  }

  async function confirmAction() {
    if (disabled || !confirmation) return;
    const result = await onMutation(confirmation);
    if (result.confirmed) {
      setConfirmation(null);
      if (confirmation.key === "resolve") setResolutionNote("");
    }
  }

  function prepareAssignment(event) {
    event.preventDefault();
    if (!technicianId || disabled || technicians.pending || technicians.error) return;
    const selected = technicians.result?.find((technician) => String(technician.id) === technicianId);
    if (!selected) return;
    prepareConfirmation({
      key: "assignment", title: ticket.technician ? "Confirmer la réaffectation" : "Confirmer l’affectation",
      message: `Affecter ce ticket à ${selected.name}${ticket.technician ? ` à la place de ${ticket.technician.name}` : ""} ? Son statut sera conservé.`,
      successMessage: "L’affectation du ticket a été enregistrée.",
      execute: () => assignTicket(ticket.id, Number(technicianId)),
    });
  }

  function prepareResolution(event) {
    event.preventDefault();
    const note = resolutionNote.trim();
    if (!note || note.length > 10000) {
      setResolutionError("Indiquez une note de résolution non vide, de 10 000 caractères maximum.");
      return;
    }
    setResolutionError(null);
    confirmStatus("resolve", "resolved", "Confirmer la résolution", "Le ticket sera marqué comme résolu avec la note ci-dessous. Le demandeur pourra ensuite le clôturer ou le rouvrir.", "Le ticket a été résolu.", { resolution_note: note });
  }

  const locked = disabled || Boolean(confirmation);
  const hasAction = capabilities.canEdit || capabilities.canAssign || capabilities.canStart || capabilities.canResolve || capabilities.canClose || capabilities.canReopen;

  return (
    <section className="ticket-panel ticket-detail-actions" aria-labelledby="ticket-actions-title">
      <h2 id="ticket-actions-title">Actions sur le ticket</h2>
      {!hasAction && <p className="ticket-muted">{capabilities.isReadOnly ? "Ce ticket est clos. Ses informations, commentaires et événements restent consultables." : "Aucune action de workflow n’est disponible pour votre rôle à cette étape."}</p>}
      {capabilities.canEdit && (editing ? <TicketActionsEdit ticket={ticket} canEditPriority={capabilities.canEditPriority} disabled={locked} error={error} onMutation={onMutation} onCancel={() => setEditing(false)} /> : <button className="ticket-button" type="button" onClick={() => setEditing(true)} disabled={locked}>Modifier les informations</button>)}
      {capabilities.canAssign && (
        <form className="ticket-detail-form" onSubmit={prepareAssignment}>
          <h3>Affectation</h3>
          <TicketField name="technician_id" label="Technicien actif" as="select" value={technicianId} onChange={(event) => setTechnicianId(event.target.value)} disabled={locked || technicians.pending || Boolean(technicians.error)} error={error?.target === "assignment" ? error.fields?.technician_id : null} hint={technicians.pending ? "Chargement de tous les techniciens actifs…" : "L’affectation ne change pas le statut du ticket."}>
            <option value="">Choisir un technicien actif</option>
            {ticket.technician && !currentTechnicianListed && <option value={ticket.technician.id} disabled>{ticket.technician.name} — affectation actuelle indisponible</option>}
            {(technicians.result ?? []).map((technician) => <option key={technician.id} value={technician.id}>{technician.name}</option>)}
          </TicketField>
          {technicians.error && <div className="ticket-alert" role="alert"><p>{technicians.error.message}</p><button className="ticket-button" type="button" onClick={technicians.load} disabled={locked || technicians.pending}>Recharger les techniciens</button></div>}
          {!technicians.pending && !technicians.error && !technicians.result?.length && <p className="ticket-muted">Aucun technicien actif disponible.</p>}
          <div className="ticket-actions">
            <button className="ticket-button ticket-button-primary" type="submit" disabled={locked || technicians.pending || Boolean(technicians.error) || !technicianId || technicianId === String(ticket.technician?.id)}>{ticket.technician ? "Réaffecter" : "Affecter"}</button>
            {capabilities.canUnassign && <button className="ticket-button" type="button" disabled={locked} onClick={() => prepareConfirmation({ key: "assignment", title: "Retirer l’affectation", message: `Retirer ${ticket.technician.name} de ce ticket ouvert ?`, successMessage: "L’affectation a été retirée.", execute: () => assignTicket(ticket.id, null) })}>Retirer l’affectation</button>}
          </div>
        </form>
      )}
      <div className="ticket-actions ticket-detail-transitions">
        {capabilities.canStart && <button className="ticket-button ticket-button-primary" type="button" disabled={locked} onClick={() => confirmStatus("start", "in_progress", "Démarrer le traitement", `Démarrer le traitement avec ${ticket.technician.name} ? Le technicien doit être toujours actif.`, "Le traitement du ticket a démarré.")}>Démarrer le traitement</button>}
        {capabilities.canClose && <button className="ticket-button ticket-button-danger" type="button" disabled={locked} onClick={() => confirmStatus("close", "closed", "Clôturer définitivement le ticket", "La clôture est définitive : il ne sera plus possible de modifier ce ticket ni d’y ajouter de commentaire.", "Le ticket a été clôturé.")}>Clôturer</button>}
        {capabilities.canReopen && <button className="ticket-button" type="button" disabled={locked} onClick={() => confirmStatus("reopen", "open", "Rouvrir le ticket", "Le ticket redeviendra ouvert et conservera son technicien. La résolution courante sera effacée ; son ancienne note restera dans l’historique.", "Le ticket a été rouvert.")}>Rouvrir</button>}
      </div>
      {capabilities.canResolve && (
        <form className="ticket-detail-form" onSubmit={prepareResolution} noValidate>
          <h3>Résoudre le ticket</h3>
          <TicketField name="resolution_note" label="Note de résolution" as="textarea" rows={4} value={resolutionNote} onChange={(event) => { setResolutionNote(event.target.value); setResolutionError(null); }} required maxLength={10000} disabled={locked} error={resolutionError ?? (error?.target === "resolve" ? error.fields?.resolution_note : null)} hint="Expliquez la solution apportée, en 10 000 caractères maximum." />
          <button className="ticket-button ticket-button-primary" type="submit" disabled={locked}>Vérifier et résoudre</button>
        </form>
      )}
      {confirmation && (
        <div className="ticket-detail-confirmation" role="group" aria-labelledby="ticket-confirmation-title" tabIndex={-1} ref={confirmationRef}>
          <h3 id="ticket-confirmation-title">{confirmation.title}</h3>
          <p>{confirmation.message}</p>
          {confirmation.key === "resolve" && <p className="ticket-detail-description">{resolutionNote.trim()}</p>}
          {error?.target === confirmation.key && error.fields?.status && <p className="ticket-field-error" role="alert">{error.fields.status}</p>}
          <div className="ticket-actions"><button className={`ticket-button ${confirmation.key === "close" ? "ticket-button-danger" : "ticket-button-primary"}`} type="button" disabled={disabled} onClick={confirmAction}>Confirmer</button><button className="ticket-button" type="button" disabled={disabled} onClick={cancelConfirmation}>Annuler</button></div>
        </div>
      )}
    </section>
  );
}
