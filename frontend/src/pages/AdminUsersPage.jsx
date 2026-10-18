import { useCallback, useEffect, useRef, useState } from "react";
import { Link } from "react-router-dom";
import { LifeBuoy } from "lucide-react";
import { listUsers } from "../api/users";
import { useAuth } from "../auth/useAuth";
import { roleLabel } from "../auth/roles";
import { getAuthError } from "../components/authErrors";
import UserForm from "../components/UserForm";

export default function AdminUsersPage() {
  const { user, refreshUser } = useAuth();
  const [page, setPage] = useState(1);
  const [result, setResult] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [editing, setEditing] = useState(null);
  const [notice, setNotice] = useState(null);
  const [formVersion, setFormVersion] = useState(0);
  const requestVersion = useRef(0);

  const loadUsers = useCallback((signal) => {
    const version = ++requestVersion.current;
    return listUsers(page, signal)
      .then((users) => {
        if (version === requestVersion.current && !signal?.aborted) {
          setResult(users);
          setError(null);
        }
      })
      .catch((failure) => {
        if (version === requestVersion.current && !signal?.aborted) setError(getAuthError(failure).formError);
      })
      .finally(() => {
        if (version === requestVersion.current && !signal?.aborted) setLoading(false);
      });
  }, [page]);

  useEffect(() => {
    const controller = new AbortController();
    loadUsers(controller.signal);
    return () => controller.abort();
  }, [loadUsers]);

  function reloadUsers() {
    setLoading(true);
    setError(null);
    return loadUsers();
  }

  function changePage(nextPage) {
    setLoading(true);
    setError(null);
    setPage(nextPage);
  }

  async function saved(account, wasEditing) {
    if (account.id === user.id) {
      await refreshUser(account);
      if (!account.is_active || account.role !== "admin") return;
    }
    setNotice(wasEditing ? "Le compte a été modifié." : "Le compte a été créé.");
    setEditing(null);
    setFormVersion((current) => current + 1);
    await reloadUsers();
  }

  function edit(account) {
    setEditing(account);
    setNotice(null);
  }

  return (
    <div className="dashboard-page">
      <header className="dashboard-header">
        <Link className="auth-brand auth-brand-dark" to="/dashboard">
          <span className="auth-brand-mark" aria-hidden="true"><LifeBuoy size={22} /></span>
          <span>SupportOps</span>
        </Link>
        <nav className="dashboard-nav" aria-label="Navigation principale">
          <Link to="/dashboard">Tableau de bord</Link>
          <Link to="/tickets">Tickets</Link>
          <Link to="/tickets/new">Nouveau ticket</Link>
          <Link to="/admin/users" aria-current="page">Utilisateurs</Link>
        </nav>
      </header>
      <main className="dashboard-main admin-main">
        <div className="dashboard-intro">
          <span className="auth-eyebrow">Administration</span>
          <h1>Utilisateurs</h1>
          <p>Créez les comptes et gérez les rôles et l’accès à SupportOps.</p>
        </div>
        {notice && <p className="admin-success" role="status">{notice}</p>}
        <div className="admin-grid">
          <section className="admin-panel" aria-labelledby="users-list-title" aria-busy={loading}>
            <div className="admin-panel-heading">
              <h2 id="users-list-title">Comptes utilisateurs</h2>
              <button className="admin-button" type="button" onClick={reloadUsers} disabled={loading}>Actualiser</button>
            </div>
            {loading ? <p role="status">Chargement des utilisateurs…</p> : error ? (
              <div className="auth-alert admin-list-error" role="alert">
                <p>{error}</p>
                <button className="admin-button" type="button" onClick={reloadUsers}>Réessayer</button>
              </div>
            ) : result?.data?.length ? <>
              <div className="admin-table-scroll" tabIndex={0} role="region" aria-label="Liste des utilisateurs, défilement horizontal possible">
                <table className="admin-table">
                  <caption className="sr-only">Utilisateurs, rôles et activation</caption>
                  <thead><tr><th scope="col">Nom et e-mail</th><th scope="col">Rôle</th><th scope="col">État</th><th scope="col">Action</th></tr></thead>
                  <tbody>{result.data.map((account) => (
                    <tr key={account.id}>
                      <td><strong>{account.name}{account.id === user.id ? " (vous)" : ""}</strong><span className="admin-user-email">{account.email}</span></td>
                      <td>{roleLabel(account.role)}</td>
                      <td><span className={`admin-badge ${account.is_active ? "active" : "inactive"}`}>{account.is_active ? "Actif" : "Désactivé"}</span></td>
                      <td><button className="admin-button" type="button" onClick={() => edit(account)} aria-label={`Modifier le compte de ${account.name}`}>Modifier</button></td>
                    </tr>
                  ))}</tbody>
                </table>
              </div>
              <nav className="admin-pagination" aria-label="Pagination des utilisateurs">
                <button className="admin-button" type="button" disabled={page <= 1} onClick={() => changePage(page - 1)}>Précédent</button>
                <span>Page {result.current_page} sur {result.last_page} · {result.total} compte{result.total > 1 ? "s" : ""}</span>
                <button className="admin-button" type="button" disabled={page >= result.last_page} onClick={() => changePage(page + 1)}>Suivant</button>
              </nav>
            </> : <p>Aucun utilisateur à afficher.</p>}
          </section>
          <UserForm key={editing?.id ?? `create-${formVersion}`} account={editing} onSaved={saved} onCancel={() => setEditing(null)} />
        </div>
      </main>
    </div>
  );
}
