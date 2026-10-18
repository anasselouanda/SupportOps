import { Link } from "react-router-dom";
import { Archive, ArrowRight, CircleAlert, CircleCheck, Clock3, FolderOpen, LoaderCircle, Plus, RefreshCw, Ticket } from "lucide-react";
import TicketBadge from "../tickets/TicketBadge";
import { formatTicketDate } from "../tickets/ticketUi";

const scopeDescriptions = {
  employee: "Vos indicateurs et vos dernières demandes d’assistance.",
  technician: "Les tickets que vous avez créés et ceux qui vous sont assignés.",
  admin: "Une vue d’ensemble de tous les tickets de SupportOps.",
};

const metrics = [
  { key: "total", label: "Tous les tickets", icon: Ticket, to: "/tickets", tone: "total" },
  { key: "open", label: "Ouverts", icon: FolderOpen, to: "/tickets?status=open", tone: "open" },
  { key: "in_progress", label: "En cours", icon: Clock3, to: "/tickets?status=in_progress", tone: "progress" },
  { key: "resolved", label: "Résolus", icon: CircleCheck, to: "/tickets?status=resolved", tone: "resolved" },
  { key: "closed", label: "Clos", icon: Archive, to: "/tickets?status=closed", tone: "closed" },
];

const numberFormatter = new Intl.NumberFormat("fr-MA");

function DashboardStats({ stats }) {
  return (
    <section aria-labelledby="dashboard-indicators-title">
      <h2 className="sr-only" id="dashboard-indicators-title">Indicateurs des tickets</h2>
      <div className="dashboard-stats-grid">
        {metrics.map(({ key, label, icon: Icon, to, tone }) => (
          <Link className={`dashboard-stat-card dashboard-stat-${tone}`} to={to} key={key}>
            <span className="dashboard-stat-icon" aria-hidden="true"><Icon size={20} /></span>
            <span className="dashboard-stat-label">{label}</span>
            <strong className="dashboard-stat-value">{typeof stats[key] === "number" ? numberFormatter.format(stats[key]) : "Indisponible"}</strong>
            <span className="dashboard-stat-action">Voir les tickets <ArrowRight size={15} aria-hidden="true" /></span>
          </Link>
        ))}
      </div>
    </section>
  );
}

function DashboardRecentTickets({ tickets }) {
  return (
    <section className="dashboard-recent-panel" aria-labelledby="dashboard-recent-title">
      <div className="dashboard-section-heading">
        <div><h2 id="dashboard-recent-title">Derniers tickets</h2><p>Les cinq demandes les plus récentes de votre périmètre.</p></div>
        <Link className="dashboard-text-link" to="/tickets">Tous les tickets <ArrowRight size={16} aria-hidden="true" /></Link>
      </div>
      {tickets.length === 0 ? (
        <div className="dashboard-empty-state">
          <span className="dashboard-empty-icon" aria-hidden="true"><FolderOpen size={28} /></span>
          <h3>Aucun ticket à afficher</h3>
          <p>Les demandes accessibles à votre compte apparaîtront ici.</p>
          <Link className="dashboard-text-link" to="/tickets/new">Créer un premier ticket <ArrowRight size={16} aria-hidden="true" /></Link>
        </div>
      ) : (
        <ul className="dashboard-recent-list">
          {tickets.slice(0, 5).map((ticket) => (
            <li key={ticket.id} className="dashboard-recent-ticket">
              <div className="dashboard-ticket-heading">
                <span className="dashboard-ticket-reference">{ticket.reference}</span>
                <Link className="dashboard-ticket-title" to={`/tickets/${encodeURIComponent(String(ticket.id))}`}>{ticket.title}</Link>
              </div>
              <div className="dashboard-ticket-badges"><TicketBadge value={ticket.status} kind="status" /><TicketBadge value={ticket.priority} kind="priority" /></div>
              <dl className="dashboard-ticket-assignment">
                <div><dt>Catégorie</dt><dd>{ticket.category?.name ?? "Indisponible"}</dd></div>
                <div><dt>Technicien</dt><dd>{ticket.technician?.name ?? "Non assigné"}</dd></div>
              </dl>
              <div className="dashboard-ticket-date"><span>Créé le</span><time dateTime={ticket.created_at}>{formatTicketDate(ticket.created_at)}</time></div>
            </li>
          ))}
        </ul>
      )}
      {tickets.length > 0 && <p className="dashboard-timezone-note">Dates affichées à l’heure du Maroc.</p>}
    </section>
  );
}

export default function DashboardContent({ user, data, pending = false, error, onRefresh }) {
  const message = typeof error === "string" ? error : error?.message;
  const hasData = !error && Boolean(data?.stats && Array.isArray(data?.recent_tickets));

  return (
    <div className="dashboard-content">
      <div className="dashboard-overview-heading">
        <div className="dashboard-intro">
          <span className="auth-eyebrow">Tableau de bord</span>
          <h1>Bienvenue, {user?.name || "Utilisateur"}</h1>
          <p>{scopeDescriptions[user?.role] ?? "Les tickets accessibles à votre compte SupportOps."}</p>
        </div>
        <div className="dashboard-overview-actions">
          <Link className="dashboard-action-button dashboard-action-primary" to="/tickets/new"><Plus size={18} aria-hidden="true" /> Nouveau ticket</Link>
          <button className="dashboard-action-button" type="button" onClick={onRefresh} disabled={pending}>
            {pending ? <LoaderCircle className="auth-spinner" size={17} aria-hidden="true" /> : <RefreshCw size={17} aria-hidden="true" />}
            {pending ? "Actualisation…" : "Actualiser"}
          </button>
        </div>
      </div>
      <div className="dashboard-data-region" aria-busy={pending}>
        {pending && <p className="dashboard-loading-status" role="status">{hasData ? "Actualisation de vos indicateurs et de vos derniers tickets…" : "Chargement de vos indicateurs et de vos derniers tickets…"}</p>}
        {error ? (
          <section className="dashboard-data-error" role="alert" aria-labelledby="dashboard-error-title">
            <CircleAlert size={23} aria-hidden="true" />
            <div><h2 id="dashboard-error-title">Le tableau de bord est indisponible</h2><p>{message || "Impossible de charger les données. Réessayez dans un instant."}</p><p className="dashboard-error-explanation">Les indicateurs et les derniers tickets n’ont pas pu être chargés.</p><button className="dashboard-action-button" type="button" onClick={onRefresh} disabled={pending}>Réessayer</button></div>
          </section>
        ) : hasData ? <><DashboardStats stats={data.stats} /><DashboardRecentTickets tickets={data.recent_tickets} /></> : !pending && <section className="dashboard-data-error" role="alert"><p>Les données du tableau de bord sont indisponibles.</p><button className="dashboard-action-button" type="button" onClick={onRefresh}>Réessayer</button></section>}
      </div>
    </div>
  );
}
