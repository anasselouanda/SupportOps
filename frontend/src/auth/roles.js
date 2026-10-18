export const ROLE_LABELS = {
  employee: "Employé",
  technician: "Technicien",
  admin: "Administrateur",
};

export const ROLES = Object.keys(ROLE_LABELS);

export function roleLabel(role) {
  return ROLE_LABELS[role] ?? "Rôle inconnu";
}
