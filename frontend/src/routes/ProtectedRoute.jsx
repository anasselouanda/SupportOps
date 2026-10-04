import { Link, Navigate, Outlet, useLocation } from "react-router-dom";
import { useAuth } from "../auth/useAuth";

export default function ProtectedRoute({ children }) {
  const { user, loading, sessionError } = useAuth();
  const location = useLocation();

  if (loading) {
    return <p className="auth-route-loading" role="status">Vérification de votre session…</p>;
  }
  if (sessionError) {
    return (
      <div className="auth-route-error" role="alert">
        <p>{sessionError}</p>
        <button type="button" onClick={() => window.location.reload()}>Réessayer</button>
        <Link to="/login">Se connecter</Link>
      </div>
    );
  }
  if (!user) return <Navigate to="/login" replace state={{ from: location }} />;

  return children ?? <Outlet />;
}
