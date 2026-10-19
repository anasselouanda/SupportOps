import { useEffect, useRef, useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import { ArrowLeft, CircleAlert, LoaderCircle, Plus } from "lucide-react";
import { createTicket, listCategories } from "../api/tickets";
import TicketLayout from "../tickets/TicketLayout";
import TicketField from "../tickets/TicketField";
import { PRIORITY_OPTIONS, ticketError } from "../tickets/ticketUi";
import "../tickets/list.css";

export default function NewTicketPage() {
  const navigate = useNavigate();
  const [form, setForm] = useState({ title: "", description: "", category_id: "", priority: "normal" });
  const [categoryRetry, setCategoryRetry] = useState(0);
  const [categoryResponse, setCategoryResponse] = useState(null);
  const [pending, setPending] = useState(false);
  const [errors, setErrors] = useState({});
  const [failure, setFailure] = useState(null);
  const [uncertainFailure, setUncertainFailure] = useState(null);
  const awaitingVerification = useRef(false);
  const submitting = useRef(false);
  const requestVersion = useRef(0);
  const mounted = useRef(false);
  const formRef = useRef(null);

  useEffect(() => {
    mounted.current = true;
    return () => { mounted.current = false; };
  }, []);

  useEffect(() => {
    const controller = new AbortController();
    let active = true;
    const version = ++requestVersion.current;
    listCategories(controller.signal)
      .then((categories) => {
        if (active && !controller.signal.aborted && version === requestVersion.current) setCategoryResponse({ key: categoryRetry, categories });
      })
      .catch((error) => {
        const details = ticketError(error);
        if (active && !controller.signal.aborted && version === requestVersion.current && details.kind !== "cancelled") setCategoryResponse({ key: categoryRetry, error: details });
      });
    return () => { active = false; controller.abort(); };
  }, [categoryRetry]);

  const categoriesLoading = categoryResponse?.key !== categoryRetry;
  const categoryError = categoriesLoading ? null : categoryResponse?.error;
  const categories = categoriesLoading ? [] : categoryResponse?.categories ?? [];
  const canCreate = !categoriesLoading && !categoryError && categories.length > 0;

  function updateField(event) {
    const { name, value } = event.target;
    setForm((current) => ({ ...current, [name]: value }));
    setErrors((current) => ({ ...current, [name]: undefined }));
    setFailure(null);
  }

  async function submit(event) {
    event.preventDefault();
    if (!mounted.current || submitting.current || awaitingVerification.current || !canCreate) return;
    if (!categories.some((category) => String(category.id) === form.category_id)) {
      setErrors((current) => ({ ...current, category_id: "Choisissez une catégorie disponible." }));
      formRef.current?.elements.namedItem("category_id")?.focus();
      return;
    }
    submitting.current = true;
    setPending(true);
    setErrors({});
    setFailure(null);
    try {
      const created = await createTicket({ title: form.title, description: form.description, category_id: Number(form.category_id), priority: form.priority });
      if (mounted.current) navigate(`/tickets/${created.id}`, { replace: true });
    } catch (error) {
      if (!mounted.current) return;
      const details = ticketError(error, { uncertain: true });
      if (details.kind !== "cancelled") {
        setErrors(details.fields ?? {});
        if (!error.response || error.response.status >= 500) {
          awaitingVerification.current = true;
          setUncertainFailure(details);
        } else {
          setFailure(details);
        }
        const firstField = ["title", "description", "category_id", "priority"].find((field) => details.fields?.[field]);
        if (firstField) requestAnimationFrame(() => {
          if (mounted.current) formRef.current?.elements.namedItem(firstField)?.focus();
        });
      }
    } finally {
      submitting.current = false;
      if (mounted.current) setPending(false);
    }
  }

  function acknowledgeVerification() {
    if (!mounted.current || submitting.current || !awaitingVerification.current) return;
    awaitingVerification.current = false;
    setUncertainFailure(null);
  }

  return (
    <TicketLayout title="Nouveau ticket" description="Décrivez votre besoin pour permettre une prise en charge efficace.">
      <section className="ticket-create-panel" aria-labelledby="ticket-create-heading">
        <div className="ticket-create-heading"><span className="ticket-create-icon" aria-hidden="true"><Plus size={22} /></span><div><h2 id="ticket-create-heading">Votre demande</h2><p>Tous les champs sont obligatoires.</p></div></div>
        {failure && <div className="ticket-list-error" role="alert"><CircleAlert size={21} aria-hidden="true" /><div><p>{failure.message}</p></div></div>}
        {uncertainFailure && <div className="ticket-list-error" role="alert"><CircleAlert size={21} aria-hidden="true" /><div><p>{uncertainFailure.message}</p><p>Vérifiez la <Link to="/tickets" target="_blank" rel="noopener noreferrer">liste des tickets dans un nouvel onglet</Link> afin de conserver votre brouillon. Si votre demande existe déjà, ouvrez ce ticket sans renvoyer le formulaire. Sinon, confirmez votre vérification avant un nouvel envoi.</p><button className="ticket-list-button" type="button" onClick={acknowledgeVerification} disabled={pending}>J’ai vérifié la liste des tickets</button></div></div>}
        <form ref={formRef} onSubmit={submit} noValidate aria-busy={pending}>
          <fieldset className="ticket-create-fields" disabled={pending}>
            <legend className="sr-only">Informations du nouveau ticket</legend>
            <TicketField name="title" label="Titre" value={form.title} onChange={updateField} error={errors.title} hint="Un titre précis, entre 3 et 255 caractères." minLength={3} maxLength={255} required placeholder="Ex. Impossible d’accéder à ma messagerie" />
            <TicketField as="textarea" name="description" label="Description" value={form.description} onChange={updateField} error={errors.description} hint="Décrivez le problème, son contexte et les étapes déjà essayées. Entre 10 et 10 000 caractères." minLength={10} maxLength={10000} rows={8} required />
            <div className="ticket-create-options">
              <div className="ticket-create-category" aria-busy={categoriesLoading}>
                <TicketField as="select" name="category_id" label="Catégorie" value={form.category_id} onChange={updateField} error={errors.category_id} required disabled={pending || !canCreate}>
                  <option value="">Choisir une catégorie</option>
                  {categories.map((category) => <option key={category.id} value={category.id}>{category.name}</option>)}
                </TicketField>
                {categoriesLoading ? <p className="ticket-create-category-notice" role="status">Chargement des catégories…</p> : categoryError ? <div className="ticket-create-category-notice ticket-create-category-error" role="alert"><p>{categoryError.message}</p><button className="ticket-list-button" type="button" disabled={pending} onClick={() => setCategoryRetry((current) => current + 1)}>Réessayer</button></div> : categories.length === 0 ? <p className="ticket-create-category-notice" role="status">Aucune catégorie active n’est disponible. Contactez un administrateur pour pouvoir créer votre ticket.</p> : null}
              </div>
              <TicketField as="select" name="priority" label="Priorité" value={form.priority} onChange={updateField} error={errors.priority} required>
                {PRIORITY_OPTIONS.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
              </TicketField>
            </div>
            <div className="ticket-create-actions"><button className="ticket-list-button" type="button" onClick={() => navigate("/tickets")} disabled={pending}><ArrowLeft size={17} aria-hidden="true" />Retour aux tickets</button><button className="ticket-list-button ticket-list-button-primary" type="submit" disabled={pending || !canCreate || Boolean(uncertainFailure)}>{pending ? <LoaderCircle className="auth-spinner" size={18} aria-hidden="true" /> : <Plus size={18} aria-hidden="true" />}{pending ? "Création en cours…" : "Créer le ticket"}</button></div>
          </fieldset>
        </form>
      </section>
    </TicketLayout>
  );
}
