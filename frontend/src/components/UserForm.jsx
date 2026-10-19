import { useEffect, useRef, useState } from "react";
import AuthField from "./AuthField";
import { getAuthError } from "./authErrors";
import { ROLES, roleLabel } from "../auth/roles";
import { createUser, updateUser } from "../api/users";

export default function UserForm({ account, onSaved, onCancel, busy = false, onBeginSave, onEndSave }) {
  const editing = Boolean(account);
  const [form, setForm] = useState({
    name: account?.name ?? "",
    email: account?.email ?? "",
    role: account?.role ?? "employee",
    is_active: account?.is_active ?? true,
    password: "",
    password_confirmation: "",
  });
  const [errors, setErrors] = useState({});
  const [error, setError] = useState(null);
  const [pending, setPending] = useState(false);
  const submitting = useRef(false);
  const mounted = useRef(false);
  const disabled = pending || busy;

  useEffect(() => {
    mounted.current = true;
    return () => { mounted.current = false; };
  }, []);

  function updateField(event) {
    const { name, value, type, checked } = event.target;
    setForm((current) => ({ ...current, [name]: type === "checkbox" ? checked : value }));
    setErrors((current) => ({ ...current, [name]: undefined }));
    setError(null);
  }

  async function submit(event) {
    event.preventDefault();
    if (!mounted.current || submitting.current || busy) return;
    const sensitive = editing
      ? form.email !== account.email || form.role !== account.role || form.is_active !== account.is_active
      : form.role === "admin" || !form.is_active;
    if (sensitive && !window.confirm(
      `${editing ? "Confirmer les changements pour" : "Confirmer la création de"} ${form.name || form.email} ?\nE-mail de connexion : ${form.email}.\nRôle : ${roleLabel(form.role)}. Compte ${form.is_active ? "actif" : "désactivé"}.\nUne désactivation coupe l’accès ; un changement de rôle modifie les permissions.`,
    )) return;
    if (!mounted.current) return;
    submitting.current = true;
    const token = onBeginSave?.();
    if (onBeginSave && !token) {
      submitting.current = false;
      return;
    }
    setPending(true);
    setErrors({});
    setError(null);
    try {
      const payload = { name: form.name, email: form.email, role: form.role, is_active: form.is_active };
      const saved = editing
        ? await updateUser(account.id, payload)
        : await createUser({ ...payload, password: form.password, password_confirmation: form.password_confirmation });
      if (!mounted.current) return;
      setForm((current) => ({ ...current, password: "", password_confirmation: "" }));
      await onSaved(saved, editing, token);
    } catch (failure) {
      if (!mounted.current) return;
      const details = getAuthError(failure);
      setErrors(details.fieldErrors);
      setError(details.formError);
    } finally {
      submitting.current = false;
      onEndSave?.(token);
      if (mounted.current) setPending(false);
    }
  }

  return (
    <section className="admin-panel" aria-labelledby="user-form-title">
      <h2 id="user-form-title">{editing ? "Modifier le compte" : "Créer un compte"}</h2>
      <p className="admin-help">{editing ? `Compte de ${account.name}` : "Les comptes sont créés par un administrateur."}</p>
      {error && <p className="auth-alert" role="alert">{error} Vous pouvez réessayer avec le formulaire.</p>}
      <form className="auth-form admin-form" onSubmit={submit} noValidate aria-busy={disabled}>
        <fieldset disabled={disabled}>
          <legend className="sr-only">Informations du compte</legend>
          <AuthField id="name" label="Nom" value={form.name} onChange={updateField} error={errors.name} autoComplete="name" required maxLength={255} />
          <AuthField id="email" label="Adresse e-mail" type="email" value={form.email} onChange={updateField} error={errors.email} autoComplete="email" required maxLength={255} />
          <div className="auth-field">
            <label htmlFor="role">Rôle</label>
            <select id="role" name="role" value={form.role} onChange={updateField} aria-invalid={Boolean(errors.role)} aria-describedby={errors.role ? "role-error" : undefined}>
              {ROLES.map((role) => <option key={role} value={role}>{roleLabel(role)}</option>)}
            </select>
            {errors.role && <p className="auth-field-error" id="role-error" role="alert">{errors.role}</p>}
          </div>
          <div className="auth-field">
            <label className="admin-checkbox" htmlFor="is_active">
              <input id="is_active" name="is_active" type="checkbox" checked={form.is_active} onChange={updateField} aria-invalid={Boolean(errors.is_active)} aria-describedby={errors.is_active ? "is-active-error" : "is-active-help"} />
              Compte actif
            </label>
            <p className="admin-help" id="is-active-help">Un compte désactivé ne peut plus accéder à SupportOps.</p>
            {errors.is_active && <p className="auth-field-error" id="is-active-error" role="alert">{errors.is_active}</p>}
          </div>
          {!editing && <>
            <AuthField id="password" label="Mot de passe" type="password" value={form.password} onChange={updateField} error={errors.password} autoComplete="new-password" minLength={8} required />
            <p className="admin-help">Au moins huit caractères.</p>
            <AuthField id="password_confirmation" label="Confirmer le mot de passe" type="password" value={form.password_confirmation} onChange={updateField} error={errors.password_confirmation} autoComplete="new-password" minLength={8} required />
          </>}
        </fieldset>
        <button className="auth-primary-button" type="submit" disabled={disabled}>{disabled ? "Enregistrement en cours…" : editing ? "Enregistrer les changements" : "Créer le compte"}</button>
        {editing && <button className="admin-button" type="button" onClick={onCancel} disabled={disabled}>Annuler la modification</button>}
      </form>
    </section>
  );
}
