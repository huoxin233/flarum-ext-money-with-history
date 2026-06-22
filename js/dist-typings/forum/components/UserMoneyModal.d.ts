import { IFormModalAttrs } from 'flarum/common/components/FormModal';
import FormModal from 'flarum/common/components/FormModal';
import Stream from 'flarum/common/utils/Stream';
import type Mithril from 'mithril';
import type User from 'flarum/common/models/User';
interface UserMoneyModalAttrs extends IFormModalAttrs {
    user: User;
}
export default class UserMoneyModal extends FormModal<UserMoneyModalAttrs> {
    money: Stream<number>;
    oninit(vnode: Mithril.Vnode<UserMoneyModalAttrs>): void;
    className(): string;
    title(): Mithril.Children;
    content(): Mithril.Children;
    onsubmit(e: SubmitEvent): void;
}
export {};
