// Creates the reseller account used by the Paymenter extension and prints its API key.
import { db, users, apiKeys } from "./src/db";
import { generateApiKey } from "./src/middleware/auth";
import { eq } from "drizzle-orm";
import { nanoid } from "nanoid";

const email = "reseller@e2e.test";
let user = await db.query.users.findFirst({ where: eq(users.email, email) });
if (!user) {
  await db.insert(users).values({
    id: nanoid(),
    email,
    passwordHash: await Bun.password.hash("e2e-password"),
    firstName: "E2E",
    lastName: "Reseller",
    emailVerified: true,
    doNotBill: true,
  });
  user = await db.query.users.findFirst({ where: eq(users.email, email) });
}

const { key, prefix, hash } = await generateApiKey();
await db.insert(apiKeys).values({ id: nanoid(), userId: user!.id, name: "paymenter-e2e", keyHash: hash, keyPrefix: prefix, permissions: ["*"] });
console.log("API_KEY=" + key);
process.exit(0);
