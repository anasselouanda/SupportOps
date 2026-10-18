import { useEffect, useRef, useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import { CircleAlert, LifeBuoy, LoaderCircle, LogOut } from "lucide-react";
import { useAuth } from "../auth/useAuth";
import { getAuthError } from "../components/authErrors";
import { roleLabel } from "../auth/roles";
import DashboardContent from "../dashboard/DashboardContent";
import useDashboardData from "../dashboard/useDashboardData";
import "../tickets/tickets.css";
import "../dashboard/dashboard.css";

function DashboardSession({ user }) {
  const navigate = useNavigate();
  const { logout } = useAuth();
  const [pending, setPending] = useState(false);
  const [error, setError] = useState(null);
  const logoutLock = useRef(false);
  const mounted = useRef(true);
  const dashboard = useDashboardData(user);

  useEffect(() => {
    mounted.current = true;
    return () => { mounted.current = false; };
  }, []);

  async function handleLogout() {
    if (logoutLock.current) return;
    logoutLock.current = true;
    setPending(true);
    setError(null);
    try {
      await logout();
      if (!mounted.current) return;
      navigate("/login", { replace: true });
    } catch (logoutError) {
      if (!mounted.current) return;
      if (logoutError?.response?.status === 401) {
        navigate("/login", { replace: true });
        return;
      }
      setError(
        logoutError?.response?.status === 419
          ? "Déconnexion non confirmée. Réessayez."
          : getAuthError(logoutError).formError,
      );
    } finally {
      if (mounted.current) {
        logoutLock.current = false;
        setPending(false);
      }
    }
  }

  return (
    <div className="dashboard-page">
      <a className="dashboard-overview-skip" href="#dashboard-main">Aller au contenu</a>
      <header className="dashboard-header dashboard-overview-header">
        <Link className="auth-brand auth-brand-dark" to="/dashboard">
          <span className="auth-brand-mark" aria-hidden="true">
            <LifeBuoy size={22} strokeWidth={2.2} />
          </span>
          <span>SupportOps</span>
        </Link>
        <nav className="dashboard-nav" aria-label="Navigation principale">
          <Link to="/dashboard" aria-current="page">Tableau de bord</Link>
          <Link to="/tickets">Tickets</Link>
          <Link to="/tickets/new">Nouveau ticket</Link>
          {user?.role === "admin" && user.is_active && <Link to="/admin/users">Utilisateurs</Link>}
        </nav>
      </header>

      <main className="dashboard-main dashboard-overview-main" id="dashboard-main" tabIndex={-1}>
        <DashboardContent user={user} data={dashboard.data} pending={dashboard.pending} error={dashboard.error} onRefresh={dashboard.refresh} />

        {error && (
          <div className="auth-alert dashboard-alert" role="alert">
            <CircleAlert size={19} aria-hidden="true" />
            <span>{error}</span>
          </div>
        )}

        <footer className="dashboard-overview-footer">
          <p>Votre rôle : {roleLabel(user?.role)}.</p>
          <button className="dashboard-logout" type="button" onClick={handleLogout} disabled={pending}>
            {pending ? <LoaderCircle className="auth-spinner" size={18} aria-hidden="true" /> : <LogOut size={18} aria-hidden="true" />}
            <span>{pending ? "Déconnexion en cours…" : "Se déconnecter"}</span>
          </button>
        </footer>
      </main>
    </div>
  );
}

export default function DashboardPage() {
  const { user } = useAuth();
  const identity = user ? `${user.id}:${user.role}:${Boolean(user.is_active)}` : "guest";
  return <DashboardSession key={identity} user={user} />;
}
