import { Link, NavLink } from "react-router-dom";
import { LifeBuoy } from "lucide-react";
import { useAuth } from "../auth/useAuth";
import "./tickets.css";

export default function TicketLayout({ title, description, actions, children }) {
  const { user } = useAuth();
  return <div className="dashboard-page tickets-shell">
    <a className="ticket-skip" href="#tickets-main">Aller au contenu</a>
    <header className="dashboard-header ticket-header">
      <Link className="auth-brand auth-brand-dark" to="/dashboard"><span className="auth-brand-mark" aria-hidden="true"><LifeBuoy size={22} /></span>SupportOps</Link>
      <nav className="dashboard-nav ticket-nav" aria-label="Navigation principale">
        <NavLink to="/dashboard">Tableau de bord</NavLink>
        <NavLink to="/tickets" end>Tickets</NavLink>
        <NavLink to="/tickets/new">Nouveau ticket</NavLink>
        {user?.role === "admin" && user.is_active && <NavLink to="/admin/users">Utilisateurs</NavLink>}
      </nav>
    </header>
    <main className="tickets-main" id="tickets-main" tabIndex={-1}>
      <div className="ticket-page-heading"><div><p className="auth-eyebrow">Assistance · SupportOps</p><h1>{title}</h1>{description && <p className="ticket-muted">{description}</p>}</div>{actions && <div className="ticket-actions">{actions}</div>}</div>
      {children}
    </main>
  </div>;
}
