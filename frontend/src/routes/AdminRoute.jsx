import { Link, Outlet } from "react-router-dom";
import { useAuth } from "../auth/useAuth";
import ProtectedRoute from "./ProtectedRoute";

export default function AdminRoute() {
  const { user } = useAuth();

  return (
    <ProtectedRoute>
      {user?.role === "admin" && user.is_active ? <Outlet /> : (
        <main className="auth-route-error admin-forbidden" role="alert">
          <h1>Accès interdit</h1>
          <p>La gestion des utilisateurs est réservée aux administrateurs actifs.</p>
          <Link to="/dashboard">Revenir au tableau de bord</Link>
        </main>
      )}
    </ProtectedRoute>
  );
}
