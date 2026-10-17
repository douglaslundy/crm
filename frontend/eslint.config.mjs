import { defineConfig, globalIgnores } from "eslint/config";
import nextVitals from "eslint-config-next/core-web-vitals";
import nextTs from "eslint-config-next/typescript";

import quality from "./eslint-rules/index.cjs";

const eslintConfig = defineConfig([
  ...nextVitals,
  ...nextTs,
  {
    // Quality gates. quality/no-direct-data-access fica de fora: o frontend
    // não tem cliente de banco (fala só com a API).
    files: ["**/*.{ts,tsx}"],
    plugins: { quality },
    rules: {
      "quality/max-lines": ["error", { max: 350 }],
      "quality/no-direct-console": "error",
    },
  },
  {
    files: ["eslint-rules/**/*.cjs"],
    rules: { "@typescript-eslint/no-require-imports": "off" },
  },
  // Override default ignores of eslint-config-next.
  globalIgnores([
    // Default ignores of eslint-config-next:
    ".next/**",
    "out/**",
    "build/**",
    "next-env.d.ts",
  ]),
]);

export default eslintConfig;
