import { useEffect, useRef, useState } from "react";
import { Link, useSearchParams } from "react-router-dom";
import { ArrowUpRight, CircleAlert, Inbox, LoaderCircle, Plus, RotateCw } from "lucide-react";
import { listTickets } from "../api/tickets";
import TicketLayout from "../tickets/TicketLayout";
import TicketBadge from "../tickets/TicketBadge";
import TicketPagination from "../tickets/TicketPagination";
import TicketListFilters from "../tickets/TicketListFilters";
import { formatTicketDate, readTicketFilters, ticketError, writeTicketFilters } from "../tickets/ticketUi";
import "../tickets/list.css";

export default function TicketsPage() {
  const [searchParams, setSearchParams] = useSearchParams();
  const filters = readTicketFilters(searchParams);
  const queryKey = writeTicketFilters(filters).toString();
  const [retry, setRetry] = useState(0);
  const loadKey = `${queryKey}::${retry}`;
  const [response, setResponse] = useState(null);
  const requestVersion = useRef(0);

  useEffect(() => {
    const controller = new AbortController();
    let active = true;
    const version = ++requestVersion.current;
    const currentFilters = readTicketFilters(new URLSearchParams(queryKey));
    const params = { page: currentFilters.page, per_page: currentFilters.per_page };
    for (const field of ["search", "status", "priority"]) {
      if (currentFilters[field]) params[field] = currentFilters[field];
    }
    listTickets(params, controller.signal)
      .then((result) => {
        if (active && !controller.signal.aborted && version === requestVersion.current) setResponse({ key: loadKey, result });
      })
      .catch((failure) => {
        const error = ticketError(failure);
        if (active && !controller.signal.aborted && version === requestVersion.current && error.kind !== "cancelled") setResponse({ key: loadKey, error });
      });
    return () => { active = false; controller.abort(); };
  }, [queryKey, loadKey]);

  const loading = response?.key !== loadKey;
  const result = loading ? null : response?.result;
  const error = loading ? null : response?.error;
  const hasFilters = Boolean(filters.search || filters.status || filters.priority);

  function changeFilters(changes) {
    setSearchParams(writeTicketFilters({ ...filters, ...changes, page: 1 }));
  }

  return (
    <TicketLayout title="Tickets" description="Consultez vos demandes et suivez leur prise en charge." actions={<Link className="ticket-list-button ticket-list-button-primary" to="/tickets/new"><Plus size={18} aria-hidden="true" />Nouveau ticket</Link>}>
      <section className="ticket-list-panel" aria-labelledby="ticket-list-heading" aria-busy={loading}>
        <div className="ticket-list-panel-heading">
          <div><h2 id="ticket-list-heading">Vos tickets</h2><p>Les demandes accessibles à votre compte.</p></div>
          <button className="ticket-list-button" type="button" disabled={loading} onClick={() => setRetry((current) => current + 1)}><RotateCw size={16} aria-hidden="true" />Actualiser</button>
        </div>
        <TicketListFilters key={filters.search} filters={filters} onChange={changeFilters} />
        {loading ? <p className="ticket-list-state" role="status"><LoaderCircle className="auth-spinner" size={21} aria-hidden="true" />Chargement des tickets…</p> : error ? (
          <div className="ticket-list-error" role="alert"><CircleAlert size={21} aria-hidden="true" /><div><p>{error.message}</p><button className="ticket-list-button" type="button" onClick={() => setRetry((current) => current + 1)}>Réessayer</button></div></div>
        ) : result?.data?.length ? (
          <>
            <div className="ticket-list-table-region" role="region" tabIndex={0} aria-label="Liste des tickets, défilement horizontal possible">
              <table className="ticket-list-table">
                <caption className="sr-only">Tickets accessibles avec statut, priorité, catégorie, demandeur, technicien et date de création.</caption>
                <thead><tr>{["Référence", "Titre", "Statut", "Priorité", "Catégorie", "Demandeur", "Technicien", "Créé le", "Détail"].map((label) => <th key={label} scope="col">{label}</th>)}</tr></thead>
                <tbody>{result.data.map((ticket) => <tr key={ticket.id}>
                  <td className="ticket-list-reference">{ticket.reference}</td>
                  <td className="ticket-list-title"><Link to={`/tickets/${ticket.id}`}>{ticket.title}</Link></td>
                  <td><TicketBadge kind="status" value={ticket.status} /></td>
                  <td><TicketBadge kind="priority" value={ticket.priority} /></td>
                  <td>{ticket.category?.name ?? "—"}</td><td>{ticket.requester?.name ?? "—"}</td><td>{ticket.technician?.name ?? "Non affecté"}</td>
                  <td className="ticket-list-date">{formatTicketDate(ticket.created_at)}</td>
                  <td><Link className="ticket-list-open" to={`/tickets/${ticket.id}`} aria-label={`Ouvrir le ticket ${ticket.reference}`}><ArrowUpRight size={19} aria-hidden="true" /><span className="sr-only">Ouvrir</span></Link></td>
                </tr>)}</tbody>
              </table>
            </div>
            <div className="ticket-list-cards">{result.data.map((ticket) => <article className="ticket-list-card" key={ticket.id} aria-labelledby={`ticket-card-${ticket.id}`}>
              <div className="ticket-list-card-top"><span className="ticket-list-reference">{ticket.reference}</span><TicketBadge kind="status" value={ticket.status} /></div>
              <h3 id={`ticket-card-${ticket.id}`}><Link to={`/tickets/${ticket.id}`}>{ticket.title}</Link></h3>
              <dl><div><dt>Priorité</dt><dd><TicketBadge kind="priority" value={ticket.priority} /></dd></div><div><dt>Catégorie</dt><dd>{ticket.category?.name ?? "—"}</dd></div><div><dt>Demandeur</dt><dd>{ticket.requester?.name ?? "—"}</dd></div><div><dt>Technicien</dt><dd>{ticket.technician?.name ?? "Non affecté"}</dd></div><div><dt>Créé le</dt><dd>{formatTicketDate(ticket.created_at)}</dd></div></dl>
              <Link className="ticket-list-card-link" to={`/tickets/${ticket.id}`} aria-label={`Voir le détail du ticket ${ticket.reference}`}>Voir le détail<ArrowUpRight size={17} aria-hidden="true" /></Link>
            </article>)}</div>
          </>
        ) : <div className="ticket-list-empty"><Inbox size={32} aria-hidden="true" /><h3>{hasFilters ? "Aucun ticket ne correspond à ces filtres" : "Aucun ticket à afficher"}</h3><p>{hasFilters ? "Modifiez votre recherche ou réinitialisez les filtres." : Number(result?.meta?.total) > 0 ? "Cette page ne contient aucun ticket. Revenez à la première page." : "Vos demandes apparaîtront ici après leur création."}</p>{hasFilters ? <button className="ticket-list-button" type="button" onClick={() => setSearchParams(writeTicketFilters({ search: "", status: "", priority: "", page: 1, per_page: filters.per_page }))}>Réinitialiser les filtres</button> : Number(result?.meta?.total) > 0 ? <button className="ticket-list-button" type="button" onClick={() => setSearchParams(writeTicketFilters({ ...filters, page: 1 }))}>Première page</button> : <Link className="ticket-list-button" to="/tickets/new">Créer un ticket</Link>}</div>}
        {result?.meta && <TicketPagination meta={result.meta} pending={loading} label="Pagination des tickets" onPageChange={(page) => setSearchParams(writeTicketFilters({ ...filters, page }))} />}
      </section>
    </TicketLayout>
  );
}
