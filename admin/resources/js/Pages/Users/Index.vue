<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import UserController from '@/actions/App/Http/Controllers/UserController';
import InvitationController from '@/actions/App/Http/Controllers/InvitationController';
import { PlusIcon, Trash2Icon, PencilIcon, SendIcon, ShieldOffIcon, XIcon } from '@lucide/vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import AccessPicker from '@/components/AccessPicker.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Table, TableBody, TableCell, TableEmpty, TableHead, TableHeader, TableRow,
} from '@/components/ui/table';
import {
    Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter,
} from '@/components/ui/dialog';
import {
    AlertDialog, AlertDialogContent, AlertDialogHeader, AlertDialogTitle,
    AlertDialogDescription, AlertDialogFooter, AlertDialogCancel,
} from '@/components/ui/alert-dialog';

const props = defineProps({
    users: Array,
    invitations: Array,
    domains: Array,
    mailboxes: Array,
});

const page = usePage();
const me = computed(() => page.props.auth.user);

const domainName = (id) => props.domains.find((d) => d.id === id)?.name;
const mailboxEmail = (id) => props.mailboxes.find((m) => m.id === id)?.email;

function accessText(item) {
    const parts = [
        ...item.domain_ids.map(domainName),
        ...item.mailbox_ids.map(mailboxEmail),
    ].filter(Boolean);

    return parts.length ? parts.join(', ') : 'No access';
}

const expires = (iso) => new Date(iso).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' });

// Invite
const inviteOpen = ref(false);
const inviteForm = useForm({ email: '', domain_ids: [], mailbox_ids: [] });

function invite() {
    inviteForm.submit(InvitationController.store(), {
        preserveScroll: true,
        onSuccess: () => {
            inviteForm.reset();
            inviteOpen.value = false;
        },
    });
}

function resend(invitation) {
    router.post(InvitationController.resend(invitation.id), {}, { preserveScroll: true });
}

function cancel(invitation) {
    router.delete(InvitationController.destroy(invitation.id), { preserveScroll: true });
}

// Edit access
const editing = ref(null);
const editForm = useForm({ domain_ids: [], mailbox_ids: [] });

function edit(user) {
    editing.value = user;
    editForm.domain_ids = [...user.domain_ids];
    editForm.mailbox_ids = [...user.mailbox_ids];
    editForm.clearErrors();
}

function saveAccess() {
    editForm.submit(UserController.update(editing.value.id), {
        preserveScroll: true,
        onSuccess: () => (editing.value = null),
    });
}

// Reset two-factor and delete
const resetTarget = ref(null);
const deleteTarget = ref(null);

function confirmReset() {
    router.post(UserController.resetTwoFactor(resetTarget.value.id), {}, {
        preserveScroll: true,
        onFinish: () => (resetTarget.value = null),
    });
}

function confirmDelete() {
    router.delete(UserController.destroy(deleteTarget.value.id), {
        preserveScroll: true,
        onFinish: () => (deleteTarget.value = null),
    });
}
</script>

<template>
    <AppLayout>
        <Head title="Users" />
        <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-xl font-semibold">Users</h2>
            <Button @click="inviteOpen = true">
                <PlusIcon class="size-4" />
                Invite user
            </Button>
        </div>

        <section v-if="invitations.length" class="mb-6">
            <h3 class="mb-2 text-sm font-medium text-muted-foreground">Pending invitations</h3>
            <div class="rounded-xl border bg-card">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Email</TableHead>
                            <TableHead class="hidden sm:table-cell">Access</TableHead>
                            <TableHead class="hidden md:table-cell">Valid until</TableHead>
                            <TableHead></TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="invitation in invitations" :key="invitation.id">
                            <TableCell class="whitespace-normal break-all font-medium">{{ invitation.email }}</TableCell>
                            <TableCell class="hidden whitespace-normal break-all text-muted-foreground sm:table-cell">{{ accessText(invitation) }}</TableCell>
                            <TableCell class="hidden text-muted-foreground md:table-cell">{{ expires(invitation.expires_at) }}</TableCell>
                            <TableCell class="whitespace-nowrap text-right">
                                <Button variant="ghost" size="icon-sm" title="Send again" @click="resend(invitation)">
                                    <SendIcon class="size-4" />
                                </Button>
                                <Button variant="ghost" size="icon-sm" title="Cancel the invitation" @click="cancel(invitation)">
                                    <XIcon class="size-4 text-destructive" />
                                </Button>
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>
        </section>

        <div class="rounded-xl border bg-card">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>User</TableHead>
                        <TableHead class="hidden sm:table-cell">Access</TableHead>
                        <TableHead>Security</TableHead>
                        <TableHead></TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="user in users" :key="user.id">
                        <TableCell class="whitespace-normal break-all">
                            <span class="font-medium">{{ user.name }}</span>
                            <Badge v-if="user.is_admin" variant="secondary" class="ml-2">Admin</Badge>
                            <p class="text-xs text-muted-foreground">{{ user.email }}</p>
                        </TableCell>
                        <TableCell class="hidden whitespace-normal break-all text-muted-foreground sm:table-cell">
                            {{ user.is_admin ? 'All domains and mailboxes' : accessText(user) }}
                        </TableCell>
                        <TableCell>
                            <div class="flex flex-wrap gap-1">
                                <Badge :variant="user.two_factor ? 'secondary' : 'outline'">{{ user.two_factor ? '2FA on' : '2FA off' }}</Badge>
                                <Badge v-if="! user.verified" variant="outline">Unverified</Badge>
                            </div>
                        </TableCell>
                        <TableCell class="whitespace-nowrap text-right">
                            <Button v-if="! user.is_admin" variant="ghost" size="icon-sm" title="Change access" @click="edit(user)">
                                <PencilIcon class="size-4" />
                            </Button>
                            <Button variant="ghost" size="icon-sm" title="Reset two-factor authentication" :disabled="! user.two_factor" @click="resetTarget = user">
                                <ShieldOffIcon class="size-4" />
                            </Button>
                            <Button v-if="user.id !== me.id" variant="ghost" size="icon-sm" title="Delete" @click="deleteTarget = user">
                                <Trash2Icon class="size-4 text-destructive" />
                            </Button>
                        </TableCell>
                    </TableRow>
                    <TableEmpty v-if="users.length === 0" :colspan="4">No users yet.</TableEmpty>
                </TableBody>
            </Table>
        </div>

        <Dialog v-model:open="inviteOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Invite user</DialogTitle>
                    <DialogDescription>They get an e-mail with a link and set a password.</DialogDescription>
                </DialogHeader>
                <form class="space-y-4" @submit.prevent="invite">
                    <div class="space-y-1.5">
                        <Label for="invite-email">Email</Label>
                        <Input id="invite-email" v-model="inviteForm.email" type="email" placeholder="name@example.com" autofocus required />
                        <p v-if="inviteForm.errors.email" class="text-sm text-destructive">{{ inviteForm.errors.email }}</p>
                    </div>
                    <AccessPicker
                        v-model:domain-ids="inviteForm.domain_ids"
                        v-model:mailbox-ids="inviteForm.mailbox_ids"
                        :domains="domains"
                        :mailboxes="mailboxes"
                        :error="inviteForm.errors.domain_ids || inviteForm.errors.mailbox_ids"
                    />
                    <DialogFooter>
                        <Button type="submit" :disabled="inviteForm.processing">Send the invitation</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog :open="editing !== null" @update:open="(v) => !v && (editing = null)">
            <DialogContent v-if="editing">
                <DialogHeader>
                    <DialogTitle>Access of {{ editing.email }}</DialogTitle>
                </DialogHeader>
                <form class="space-y-4" @submit.prevent="saveAccess">
                    <AccessPicker
                        v-model:domain-ids="editForm.domain_ids"
                        v-model:mailbox-ids="editForm.mailbox_ids"
                        :domains="domains"
                        :mailboxes="mailboxes"
                    />
                    <DialogFooter>
                        <Button type="submit" :disabled="editForm.processing">Save</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <AlertDialog :open="resetTarget !== null" @update:open="(v) => !v && (resetTarget = null)">
            <AlertDialogContent v-if="resetTarget">
                <AlertDialogHeader>
                    <AlertDialogTitle>Reset two-factor authentication for {{ resetTarget.email }}?</AlertDialogTitle>
                    <AlertDialogDescription>
                        Use it when they lost the phone and the recovery codes. At the next sign-in they set it up again.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel>Cancel</AlertDialogCancel>
                    <Button variant="destructive" @click="confirmReset">Reset</Button>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>

        <AlertDialog :open="deleteTarget !== null" @update:open="(v) => !v && (deleteTarget = null)">
            <AlertDialogContent v-if="deleteTarget">
                <AlertDialogHeader>
                    <AlertDialogTitle>Delete {{ deleteTarget.email }}?</AlertDialogTitle>
                    <AlertDialogDescription>The user loses access to the panel. Domains and mailboxes stay as they are.</AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel>Cancel</AlertDialogCancel>
                    <Button variant="destructive" @click="confirmDelete">Delete</Button>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    </AppLayout>
</template>
