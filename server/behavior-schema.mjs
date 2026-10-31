// Executable sources are never accepted in this contract. Per-behavior settings
// and semantic target compatibility are also checked by the PHP registry.
export const behaviorSettingsSchema = {
  type: "object",
  additionalProperties: false,
  properties: {
    enabled: { type: "boolean" },
    durationMs: { type: "integer", minimum: 100, maximum: 600 },
    showCaption: { type: "boolean" },
  },
};
export const behaviorBindingsSchema = {
  type: "array",
  maxItems: 4,
  items: {
    type: "object",
    additionalProperties: false,
    required: ["key", "id", "revision", "target", "settings"],
    properties: {
      key: { type: "string", pattern: "^[a-z][a-z0-9_]{0,39}$" },
      id: { type: "string", enum: ["fade-in", "image-zoom"] },
      revision: { type: "string", pattern: "^[a-f0-9]{64}$" },
      target: { type: "string", pattern: "^(root|[a-z][a-z0-9_]{0,79})$" },
      settings: behaviorSettingsSchema,
    },
  },
};
