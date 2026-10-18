import { useCallback, useEffect, useRef, useState } from "react";
import { Link, useParams } from "react-router-dom";
import { getTicket, listTicketComments, listTicketEvents } from "../api/tickets";
import { useAuth } from "../auth/useAuth";
import TicketLayout from "../tickets/TicketLayout";
import TicketDetailFacts from "../tickets/TicketDetailFacts";
import TicketActionsPanel from "../tickets/TicketActionsPanel";
import TicketActionsComment from "../tickets/TicketActionsComment";
import TicketActivityPanel from "../tickets/TicketActivityPanel";
import { useTicketDetailResource } from "../tickets/TicketDetailResource";
import { ticketCapabilities, ticketError } from "../tickets/ticketUi";
import "../tickets/detail.css";

function TicketDetailSession({ ticketId }) {
  const { user } = useAuth();
  const [commentPage, setCommentPage] = useState(1);
  const [eventPage, setEventPage] = useState(1);
  const [pending, setPending] = useState(null);
  const [actionError, setActionError] = useState(null);
  const [notice, setNotice] = useState(null);
  const [warning, setWarning] = useState(null);
  const [mustRefresh, setMustRefresh] = useState(false);
  const mutationLock = useRef(false);
  const active = useRef(true);
  const errorRef = useRef(null);
  const loadTicket = useCallback((signal) => getTicket(ticketId, signal), [ticketId]);
  const loadComments = useCallback((signal) => listTicketComments(ticketId, { page: commentPage, per_page: 15 }, signal), [ticketId, commentPage]);
  const loadEvents = useCallback((signal) => listTicketEvents(ticketId, { page: eventPage, per_page: 15 }, signal), [ticketId, eventPage]);
  const detail = useTicketDetailResource(loadTicket);
  const comments = useTicketDetailResource(loadComments);
  const events = useTicketDetailResource(loadEvents);

  useEffect(() => {
    active.current = true;
    return () => { active.current = false; };
  }, []);

  useEffect(() => {
    if (actionError) errorRef.current?.focus();
  }, [actionError]);

  async function reloadAll() {
    return Promise.all([detail.load(), comments.load(), events.load()]);
  }

  async function refresh() {
    if (mutationLock.current) return;
    mutationLock.current = true;
    setPending("refresh");
    try {
      const results = await reloadAll();
      if (!active.current) return;
      if (results.every(Boolean)) {
        setWarning(null);
        setActionError(null);
        setMustRefresh(false);
      } else {
        setWarning("Certaines données n’ont pas pu être actualisées. Les dernières informations reçues restent affichées. Actualisez à nouveau avant toute nouvelle écriture.");
        setMustRefresh(true);
      }
    } finally {
      if (active.current) {
        mutationLock.current = false;
        setPending(null);
      }
    }
  }

  async function mutate({ key, execute, successMessage }) {
    if (mutationLock.current || mustRefresh) return { confirmed: false };
    mutationLock.current = true;
    setPending(key);
    setActionError(null);
    setNotice(null);
    try {
      await execute();
      if (!active.current) return { confirmed: true };
      const results = await reloadAll();
      if (!active.current) return { confirmed: true };
      if (results.every(Boolean)) {
        setNotice(successMessage);
        setWarning(null);
      } else {
        setWarning("L’action a été enregistrée, mais le ticket ou ses activités n’ont pas pu être entièrement actualisés. Les dernières données reçues restent affichées. Actualisez avant de poursuivre ; ne renvoyez pas l’action.");
        setMustRefresh(true);
      }
      return { confirmed: true };
    } catch (failure) {
      if (!active.current) return { confirmed: false };
      const error = ticketError(failure, { uncertain: true });
      if (error.kind === "cancelled") return { confirmed: false };
      setActionError({ ...error, target: key });
      if (["not-found", "session"].includes(error.kind)) {
        detail.invalidate(error);
        comments.invalidate(error);
        events.invalidate(error);
      }
      if (["network", "server", "conflict"].includes(error.kind)) setMustRefresh(true);
      return { confirmed: false };
    } finally {
      if (active.current) {
        mutationLock.current = false;
        setPending(null);
      }
    }
  }

  const ticket = detail.result;
  const capabilities = ticketCapabilities(user, ticket);
  const busy = Boolean(pending);
  const disabled = busy || mustRefresh || detail.pending;
  const commentIds = comments.result?.data?.map((comment) => comment.id) ?? [];

  return (
    <TicketLayout title="Détail du ticket" description="Consultez la demande, échangez avec les intervenants et suivez son traitement." actions={<Link className="ticket-button" to="/tickets">Retour aux tickets</Link>}>
      <div className="ticket-detail-toolbar">
        <p className="ticket-muted">Dates affichées à l’heure du Maroc.</p>
        <button className="ticket-button" type="button" onClick={refresh} disabled={busy || detail.pending}>Actualiser toutes les données</button>
      </div>
      {pending && <p className="ticket-muted" role="status">{pending === "refresh" ? "Actualisation du ticket et de ses activités…" : "Enregistrement et actualisation en cours…"}</p>}
      {notice && <p className="ticket-success" role="status">{notice}</p>}
      {warning && <div className="ticket-detail-warning" role="alert"><p>{warning}</p></div>}
      {mustRefresh && !warning && <div className="ticket-detail-warning" role="alert"><p>Vérifiez le résultat avec « Actualiser toutes les données » avant de renvoyer une action. Les champs saisis sont conservés.</p></div>}
      {actionError && <div className="ticket-alert ticket-detail-error-summary" role="alert" tabIndex={-1} ref={errorRef}><p>{actionError.message}</p>{Object.keys(actionError.fields).length > 0 && <ul>{[...new Set(Object.values(actionError.fields))].map((message) => <li key={message}>{message}</li>)}</ul>}</div>}
      {!ticket && detail.pending ? <div className="ticket-panel" role="status">Chargement du ticket…</div> : !ticket ? <div className="ticket-panel ticket-alert" role="alert"><h2>{detail.error?.kind === "not-found" ? "Ticket introuvable" : "Impossible de charger le ticket"}</h2><p>{detail.error?.message ?? "Réessayez dans un instant."}</p><button className="ticket-button" type="button" onClick={refresh} disabled={busy}>Réessayer</button></div> : <>
        {detail.error && <div className="ticket-alert" role="alert"><p>{detail.error.message} Les informations affichées correspondent au dernier chargement réussi.</p></div>}
        <TicketDetailFacts ticket={ticket} />
        <TicketActionsPanel key={`${ticket.id}-${ticket.technician?.id ?? "unassigned"}`} ticket={ticket} capabilities={capabilities} disabled={disabled} error={actionError} onMutation={mutate} />
        <div className="ticket-detail-activity-grid">
          <TicketActivityPanel kind="comments" resource={comments} page={commentPage} onPageChange={setCommentPage} ticket={ticket} busy={busy}>
            {capabilities.canComment ? <TicketActionsComment ticketId={ticket.id} disabled={disabled} error={actionError} onMutation={mutate} /> : <p className="ticket-muted ticket-detail-comment-closed">Les commentaires restent consultables. Ce ticket ne permet plus d’ajouter de commentaire.</p>}
          </TicketActivityPanel>
          <TicketActivityPanel kind="events" resource={events} page={eventPage} onPageChange={setEventPage} ticket={ticket} busy={busy} commentIds={commentIds} />
        </div>
      </>}
    </TicketLayout>
  );
}

export default function TicketDetailPage() {
  const { ticketId } = useParams();
  return <TicketDetailSession key={ticketId} ticketId={ticketId} />;
}
