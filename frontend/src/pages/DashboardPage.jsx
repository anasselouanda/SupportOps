import { useState } from "react";
import { useNavigate } from "react-router-dom";
import { CircleAlert, LifeBuoy, LoaderCircle, LogOut, Mail, ShieldCheck, UserRound } from "lucide-react";
import { useAuth } from "../auth/useAuth";
import { getAuthError } from "../components/authErrors";

export default function DashboardPage() {
  const navigate = useNavigate();
  const { user, logout } = useAuth();
  const [pending, setPending] = useState(false);
  const [error, setError] = useState(null);

  async function handleLogout() {
    if (pending) return;
    setPending(true);
    setError(null);
    try {
      await logout();
      navigate("/login", { replace: true });
    } catch (logoutError) {
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
      setPending(false);
    }
  }

  const name = user?.name || "Utilisateur";
  const email = user?.email || "—";
  const initial = name.trim().charAt(0).toLocaleUpperCase() || "U";

  return (
    <div className="dashboard-page">
      <header className="dashboard-header">
        <div className="auth-brand auth-brand-dark">
          <span className="auth-brand-mark" aria-hidden="true">
            <LifeBuoy size={22} strokeWidth={2.2} />
          </span>
          <span>SupportOps</span>
        </div>
        <span className="dashboard-header-label">Espace personnel</span>
      </header>

      <main className="dashboard-main">
        <div className="dashboard-intro">
          <span className="auth-eyebrow">Tableau de bord</span>
          <h1>Bienvenue, {name}</h1>
          <p>Vous êtes connecté à votre espace SupportOps.</p>
        </div>

        <section className="dashboard-card" aria-labelledby="account-title">
          <div className="dashboard-card-top">
            <div className="dashboard-card-icon" aria-hidden="true">
              <UserRound size={22} />
            </div>
            <div>
              <h2 id="account-title">Votre compte</h2>
              <p>Informations de votre session</p>
            </div>
          </div>
          <div className="dashboard-person">
            <span className="dashboard-avatar" aria-hidden="true">{initial}</span>
            <div className="dashboard-person-details">
              <strong>{name}</strong>
              <span><Mail size={16} aria-hidden="true" /> {email}</span>
            </div>
          </div>
          <div className="dashboard-status">
            <ShieldCheck size={19} aria-hidden="true" />
            <span>Votre session est active.</span>
          </div>
        </section>

        {error && (
          <div className="auth-alert dashboard-alert" role="alert">
            <CircleAlert size={19} aria-hidden="true" />
            <span>{error}</span>
          </div>
        )}

        <button className="dashboard-logout" type="button" onClick={handleLogout} disabled={pending}>
          {pending ? <LoaderCircle className="auth-spinner" size={18} aria-hidden="true" /> : <LogOut size={18} aria-hidden="true" />}
          <span>{pending ? "Déconnexion en cours…" : "Se déconnecter"}</span>
        </button>
      </main>
    </div>
  );
}
