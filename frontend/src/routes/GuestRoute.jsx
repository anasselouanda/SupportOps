import { Navigate, Outlet } from "react-router-dom";
import { useAuth } from "../auth/useAuth";

export default function GuestRoute({ children }) {
  const { user, loading } = useAuth();

  if (loading) {
    return <p className="auth-route-loading" role="status">Vérification de votre session…</p>;
  }
  if (user) return <Navigate to="/dashboard" replace />;

  return children ?? <Outlet />;
}
