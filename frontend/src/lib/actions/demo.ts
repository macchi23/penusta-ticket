"use server";

import { cookies } from "next/headers";
import { DEMO_USER_COOKIE, isDemoAuthEnabled } from "@/lib/demo-auth";

export async function switchDemoUser(formData: FormData): Promise<void> {
  if (!isDemoAuthEnabled()) return;
  const raw = String(formData.get("user_id") ?? "").trim();
  const store = await cookies();

  if (raw === "") {
    store.delete(DEMO_USER_COOKIE);
    return;
  }
  if (!/^[1-9][0-9]{0,17}$/.test(raw)) return;

  store.set({
    name: DEMO_USER_COOKIE,
    value: raw,
    httpOnly: true,
    sameSite: "lax",
    path: "/",
  });
}
