export default class UserMoneyModal extends Modal<import("flarum/common/components/Modal").IInternalModalAttrs, undefined> {
    constructor();
    oninit(vnode: any): void;
    money: any;
    content(): JSX.Element;
    onsubmit(e: any): void;
}
import Modal from "flarum/common/components/Modal";
