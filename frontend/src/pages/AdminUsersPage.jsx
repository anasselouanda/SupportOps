import { useCallback, useEffect, useRef, useState } from "react";
import { Link } from "react-router-dom";
import { LifeBuoy } from "lucide-react";
import { listUsers } from "../api/users";
import { useAuth } from "../auth/useAuth";
import { roleLabel } from "../auth/roles";
import { getAuthError } from "../components/authErrors";
import UserForm from "../components/UserForm";

function AdminUsersSession({ user, refreshUser }) {
  const [page, setPage] = useState(1);
  const [result, setResult] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [editing, setEditing] = useState(null);
  const [notice, setNotice] = useState(null);
  const [formVersion, setFormVersion] = useState(0);
  const [saving, setSaving] = useState(false);
  const requestVersion = useRef(0);
  const currentRequest = useRef(null);
  const saveToken = useRef(null);
  const mounted = useRef(false);

  useEffect(() => {
    mounted.current = true;
    return () => {
      mounted.current = false;
      saveToken.current = null;
      currentRequest.current?.abort();
    };
  }, []);

  const loadUsers = useCallback(() => {
    currentRequest.current?.abort();
    const controller = new AbortController();
    currentRequest.current = controller;
    const version = ++requestVersion.current;
    const isCurrent = () => mounted.current && !controller.signal.aborted && version === requestVersion.current;
    return listUsers(page, controller.signal)
      .then((users) => {
        if (isCurrent()) {
          setResult(users);
          setError(null);
        }
      })
      .catch((failure) => {
        if (isCurrent()) setError(getAuthError(failure).formError);
      })
      .finally(() => {
        if (isCurrent()) setLoading(false);
      });
  }, [page]);

  useEffect(() => {
    loadUsers();
    return () => currentRequest.current?.abort();
  }, [loadUsers]);

  function reloadUsers() {
    if (saveToken.current) return;
    setLoading(true);
    setError(null);
    return loadUsers();
  }

  function changePage(nextPage) {
    if (saveToken.current) return;
    setLoading(true);
    setError(null);
    setPage(nextPage);
  }

  function beginSave() {
    if (!mounted.current || saveToken.current) return null;
    const token = {};
    saveToken.current = token;
    setSaving(true);
    return token;
  }

  function endSave(token) {
    if (!mounted.current || saveToken.current !== token) return;
    saveToken.current = null;
    setSaving(false);
  }

  async function saved(account, wasEditing, token) {
    const isCurrent = () => mounted.current && saveToken.current === token;
    if (!isCurrent()) return;
    if (String(account.id) === String(user.id)) {
      await refreshUser(account);
      if (!isCurrent() || !account.is_active || account.role !== "admin") return;
    }
    setNotice(wasEditing ? "Le compte a été modifié." : "Le compte a été créé.");
    setEditing(null);
    setFormVersion((current) => current + 1);
    setLoading(true);
    setError(null);
    await loadUsers();
  }

  function edit(account) {
    if (saveToken.current) return;
    setEditing(account);
    setNotice(null);
  }

  function cancelEdit() {
    if (!saveToken.current) setEditing(null);
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
        {saving && <p className="admin-help" role="status">Enregistrement en cours. La sélection du compte et la pagination sont temporairement verrouillées.</p>}
        <div className="admin-grid">
          <section className="admin-panel" aria-labelledby="users-list-title" aria-busy={loading}>
            <div className="admin-panel-heading">
              <h2 id="users-list-title">Comptes utilisateurs</h2>
              <button className="admin-button" type="button" onClick={reloadUsers} disabled={loading || saving}>Actualiser</button>
            </div>
            {loading ? <p role="status">Chargement des utilisateurs…</p> : error ? (
              <div className="auth-alert admin-list-error" role="alert">
                <p>{error}</p>
                <button className="admin-button" type="button" onClick={reloadUsers} disabled={saving}>Réessayer</button>
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
                      <td><button className="admin-button" type="button" onClick={() => edit(account)} disabled={saving} aria-label={`Modifier le compte de ${account.name}`}>Modifier</button></td>
                    </tr>
                  ))}</tbody>
                </table>
              </div>
              <nav className="admin-pagination" aria-label="Pagination des utilisateurs">
                <button className="admin-button" type="button" disabled={saving || page <= 1} onClick={() => changePage(page - 1)}>Précédent</button>
                <span>Page {result.current_page} sur {result.last_page} · {result.total} compte{result.total > 1 ? "s" : ""}</span>
                <button className="admin-button" type="button" disabled={saving || page >= result.last_page} onClick={() => changePage(page + 1)}>Suivant</button>
              </nav>
            </> : <p>Aucun utilisateur à afficher.</p>}
          </section>
          <UserForm key={editing?.id ?? `create-${formVersion}`} account={editing} busy={saving} onBeginSave={beginSave} onEndSave={endSave} onSaved={saved} onCancel={cancelEdit} />
        </div>
      </main>
    </div>
  );
}

export default function AdminUsersPage() {
  const { user, refreshUser } = useAuth();
  const identity = user ? `${user.id}:${user.role}:${Boolean(user.is_active)}` : "guest";
  return <AdminUsersSession key={identity} user={user} refreshUser={refreshUser} />;
}
