export function getAuthError(error, { login = false } = {}) {
  const status = error?.response?.status;
  const errors = error?.response?.data?.errors;
  const fieldErrors = {};

  if (status === 422 && errors && typeof errors === "object") {
    for (const [field, messages] of Object.entries(errors)) {
      const first = Array.isArray(messages) ? messages[0] : messages;
      if (typeof first === "string" && first.trim()) {
        fieldErrors[field] = first;
      }
    }

    if (login && /identifiants invalides/i.test(fieldErrors.email || "")) {
      delete fieldErrors.email;
      return { fieldErrors, formError: "Adresse e-mail ou mot de passe incorrect." };
    }

    return {
      fieldErrors,
      formError: Object.keys(fieldErrors).length ? null : "Vérifiez les informations saisies.",
    };
  }

  if (status === 401 && login) {
    return { fieldErrors, formError: "Adresse e-mail ou mot de passe incorrect." };
  }
  if (status === 419) {
    return { fieldErrors, formError: "Votre session a expiré. Réessayez." };
  }
  if (status === 429) {
    return { fieldErrors, formError: "Trop de tentatives. Patientez une minute avant de réessayer." };
  }
  if (!error?.response || status >= 500) {
    return { fieldErrors, formError: "Le serveur est indisponible. Vérifiez votre connexion et réessayez." };
  }
  return { fieldErrors, formError: "Une erreur est survenue. Réessayez." };
}
